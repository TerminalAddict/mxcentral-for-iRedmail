import importlib.machinery
import os
import stat
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


if __name__ == "__main__":
    unittest.main()
