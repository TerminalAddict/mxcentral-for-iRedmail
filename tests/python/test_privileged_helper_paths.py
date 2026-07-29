import importlib.machinery
import os
import stat
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch


HELPER_PATH = Path(__file__).resolve().parents[2] / "scripts" / "mxcentral-privileged"
helper = importlib.machinery.SourceFileLoader("mxcentral_privileged", str(HELPER_PATH)).load_module()


def directory(mode: int = 0o755, uid: int = 0) -> types.SimpleNamespace:
    return types.SimpleNamespace(st_mode=stat.S_IFDIR | mode, st_uid=uid)


def symlink(uid: int = 0) -> types.SimpleNamespace:
    return types.SimpleNamespace(st_mode=stat.S_IFLNK | 0o777, st_uid=uid)


class SecureDirectoryTest(unittest.TestCase):
    def setUp(self) -> None:
        self.previous_config = helper.ACTIVE_CONFIG
        helper.ACTIVE_CONFIG = {"web_users": []}

    def tearDown(self) -> None:
        helper.ACTIVE_CONFIG = self.previous_config

    def test_root_owned_alias_to_secure_directory_is_opened_canonically(self) -> None:
        entries = {
            "/opt": directory(),
            "/opt/iredapd": symlink(),
            "/opt/iRedAPD-6.1": directory(mode=0o500),
        }

        with (
            patch.object(helper.os, "lstat", side_effect=lambda path: entries[path]),
            patch.object(helper.os.path, "realpath", return_value="/opt/iRedAPD-6.1"),
            patch.object(helper.os, "open", return_value=42) as opened,
        ):
            self.assertEqual(42, helper.secure_directory("/opt/iredapd"))

        opened.assert_called_once_with(
            "/opt/iRedAPD-6.1",
            os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
        )

    def test_non_root_alias_is_rejected(self) -> None:
        entries = {
            "/opt": directory(),
            "/opt/iredapd": symlink(uid=1000),
        }

        with patch.object(helper.os, "lstat", side_effect=lambda path: entries[path]):
            with self.assertRaisesRegex(helper.RequestError, "unsafe directory symlink"):
                helper.secure_directory("/opt/iredapd")

    def test_insecure_resolved_target_is_rejected(self) -> None:
        entries = {
            "/opt": directory(),
            "/opt/iredapd": symlink(),
            "/opt/iRedAPD-6.1": directory(mode=0o770),
        }

        with (
            patch.object(helper.os, "lstat", side_effect=lambda path: entries[path]),
            patch.object(helper.os.path, "realpath", return_value="/opt/iRedAPD-6.1"),
        ):
            with self.assertRaisesRegex(helper.RequestError, "group/other writable"):
                helper.secure_directory("/opt/iredapd")


class DkimGenerationTest(unittest.TestCase):
    def test_generator_receives_a_nonexistent_file_in_a_private_directory(self) -> None:
        with tempfile.TemporaryDirectory() as root:
            dkim_directory = Path(root) / "dkim"
            dkim_directory.mkdir()
            generator = Path(root) / "genrsa.py"
            generator.write_text(
                "from pathlib import Path\n"
                "import sys\n"
                "target = Path(sys.argv[1])\n"
                "if target.exists():\n"
                "    print('refusing existing output', file=sys.stderr)\n"
                "    raise SystemExit(1)\n"
                "target.write_text('generated-private-key')\n",
                encoding="utf-8",
            )
            config = {
                "dkim": {
                    "directory": str(dkim_directory),
                    "owner": "unused",
                    "group": "unused",
                    "mode": "0400",
                    "genrsa": [sys.executable, str(generator)],
                },
            }

            def open_directory(path: str) -> int:
                return os.open(path, os.O_RDONLY | os.O_DIRECTORY)

            with (
                patch.object(helper, "secure_directory", side_effect=open_directory),
                patch.object(
                    helper,
                    "identity",
                    side_effect=lambda _name, group=False: os.getgid() if group else os.getuid(),
                ),
            ):
                result = helper.generate_dkim(config, {"domain": "example.com", "bits": 2048})

            self.assertFalse(result["rotated"])
            self.assertEqual("generated-private-key", (dkim_directory / "example.com.pem").read_text())
            self.assertEqual(["example.com.pem"], [path.name for path in dkim_directory.iterdir()])


class IredapdManagedBlockTest(unittest.TestCase):
    def test_configuration_preflight_validates_without_applying(self) -> None:
        prepared = (
            {"iredapd_settings": "candidate"},
            {"iredapd_settings": b"current"},
            ["iredapd_restart"],
        )
        with (
            patch.object(helper, "prepare_configuration", return_value=prepared) as prepare,
            patch.object(helper, "atomic_write") as write,
            patch.object(helper, "run_command") as command,
        ):
            result = helper.dispatch(
                {},
                {
                    "operation": "validate_configuration",
                    "parameters": {
                        "writes": {"iredapd_settings": "candidate"},
                        "commands": ["iredapd_restart"],
                    },
                },
            )

        self.assertEqual(
            {"status": "valid", "targets": ["iredapd_settings"], "commands": 1},
            result,
        )
        prepare.assert_called_once()
        write.assert_not_called()
        command.assert_not_called()

    def test_sender_mismatch_plugin_must_remain_outside_managed_block(self) -> None:
        valid = (
            "plugins = ['reject_sender_login_mismatch']\n\n"
            "# BEGIN iredadmin-php managed: login mismatch senders\n"
            "ALLOWED_LOGIN_MISMATCH_SENDERS = ['sender@example.com']\n"
            "# END iredadmin-php managed: login mismatch senders\n"
        )
        helper.validate_iredapd_managed_blocks(valid)

        invalid = valid.replace(
            "plugins = ['reject_sender_login_mismatch']\n\n",
            "",
        ).replace(
            "ALLOWED_LOGIN_MISMATCH_SENDERS",
            "plugins = ['reject_sender_login_mismatch']\nALLOWED_LOGIN_MISMATCH_SENDERS",
        )

        with self.assertRaisesRegex(helper.RequestError, "unapproved assignment"):
            helper.validate_iredapd_managed_blocks(invalid)

    def test_adjacent_sender_blocks_are_valid(self) -> None:
        content = (
            "# BEGIN iredadmin-php managed: login mismatch senders\n"
            "ALLOWED_LOGIN_MISMATCH_SENDERS = ['sender@example.com']\n"
            "# END iredadmin-php managed: login mismatch senders\n\n"
            "# BEGIN mxcentral managed: unauthenticated senders\n"
            "ALLOWED_FORGED_SENDERS = []\n"
            "MYNETWORKS = []\n"
            "# END mxcentral managed: unauthenticated senders\n"
        )

        helper.validate_iredapd_managed_blocks(content)

    def test_legacy_sender_assignment_can_only_migrate_to_managed_block(self) -> None:
        current = (
            "# iRedAPD settings\n"
            "# Custom addition by iredadmin-php\n"
            "# Allow forging email address\n"
            "ALLOWED_LOGIN_MISMATCH_SENDERS = ['old@example.com']\n"
            "MYNETWORKS = []\n"
            "plugins = ['reject_null_sender']\n"
        )
        candidate = (
            "# iRedAPD settings\n"
            "# BEGIN iredadmin-php managed: login mismatch senders\n"
            "ALLOWED_LOGIN_MISMATCH_SENDERS = ['new@example.com']\n"
            "# END iredadmin-php managed: login mismatch senders\n"
            "MYNETWORKS = []\n"
            "plugins = ['reject_null_sender', 'reject_sender_login_mismatch']\n"
        )

        helper.validate_managed_change({}, "iredapd_settings", current, candidate)

        with self.assertRaisesRegex(helper.RequestError, "outside approved"):
            helper.validate_managed_change(
                {},
                "iredapd_settings",
                current,
                current.replace("old@example.com", "new@example.com"),
            )

    def test_managed_block_insertion_must_not_leave_outside_whitespace(self) -> None:
        current = "from libs import SMTP_ACTIONS\n"
        valid = (
            "# BEGIN iredadmin-php managed: login mismatch senders\n"
            "ALLOWED_LOGIN_MISMATCH_SENDERS = []\n"
            "# END iredadmin-php managed: login mismatch senders\n"
            "from libs import SMTP_ACTIONS\n"
        )
        invalid = valid.replace(
            "# END iredadmin-php managed: login mismatch senders\n",
            "# END iredadmin-php managed: login mismatch senders\n\n",
        )

        helper.validate_managed_change({}, "iredapd_settings", current, valid)
        with self.assertRaisesRegex(helper.RequestError, "outside approved"):
            helper.validate_managed_change({}, "iredapd_settings", current, invalid)

    def test_legacy_unauthenticated_assignments_can_migrate_to_managed_block(self) -> None:
        current = (
            "# iRedAPD settings\n"
            "ALLOWED_FORGED_SENDERS = ['sender@example.com']\n"
            "\n"
            "MYNETWORKS = ['192.0.2.1']\n"
            "\n"
            "listen_port = 7777\n"
        )
        candidate = (
            "# iRedAPD settings\n"
            "# BEGIN mxcentral managed: unauthenticated senders\n"
            "ALLOWED_FORGED_SENDERS = ['sender@example.com']\n"
            "MYNETWORKS = ['192.0.2.1']\n"
            "# END mxcentral managed: unauthenticated senders\n"
            "\n"
            "\n"
            "listen_port = 7777\n"
        )

        helper.validate_managed_change({}, "iredapd_settings", current, candidate)

        with self.assertRaisesRegex(helper.RequestError, "outside approved"):
            helper.validate_managed_change(
                {},
                "iredapd_settings",
                current,
                current.replace("192.0.2.1", "198.51.100.1"),
            )


class SogoBrandingTest(unittest.TestCase):
    def test_original_and_custom_logo_colour_combinations_are_valid(self) -> None:
        color_block = (
            "<!-- BEGIN MXCentral managed SOGo login branding -->\n"
            '<style type="text/css">\n'
            ".md-default-theme.md-accent.md-bg { background-color: #123456 !important; }\n"
            "#login * { color: #abcdef !important; }\n"
            "</style>\n"
            "<!-- END MXCentral managed SOGo login branding -->\n"
        )
        current = (
            "<root><script></script>\n"
            + color_block
            + '<!-- MAIN CONTENT ROW --><img class="md-margin" src="https://example.com/custom.svg"/></root>'
        )
        candidates = [
            (
                "<root><script></script>\n"
                + color_block
                + '<!-- MAIN CONTENT ROW --><img class="md-margin" rsrc:src="img/sogo-full.svg"/></root>'
            ),
            '<root><script></script>\n<!-- MAIN CONTENT ROW --><img class="md-margin" src="https://example.com/custom.svg"/></root>',
            '<root><script></script>\n<!-- MAIN CONTENT ROW --><img class="md-margin" rsrc:src="img/sogo-full.svg"/></root>',
        ]

        for candidate in candidates:
            helper.validate_managed_change({}, "sogo_template", current, candidate)

    def test_original_logo_resource_path_is_restricted(self) -> None:
        candidate = '<root><img class="md-margin" rsrc:src="../../etc/passwd"/></root>'

        with self.assertRaisesRegex(helper.RequestError, "resource path is invalid"):
            helper.validate_sogo_branding(candidate)


if __name__ == "__main__":
    unittest.main()
