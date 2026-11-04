import importlib.machinery
import unittest
from pathlib import Path

helper = importlib.machinery.SourceFileLoader(
    'dkim_precedence_helper',
    str(Path(__file__).resolve().parents[2] / 'scripts' / 'mxcentral-privileged'),
).load_module()


class DkimMapPrecedenceTest(unittest.TestCase):
    def block(self, verb):
        return "\n".join([
            "# BEGIN mxcentral-for-iRedmail managed DKIM keys",
            "dkim_key('example.com', 'mxcentral', '/var/lib/dkim/example.com.pem');",
            verb + " @dkim_signature_options_bysender_maps, {",
            "'example.com' => { d => 'example.com', a => 'rsa-sha256', ttl => 10*24*3600 },",
            "};",
            "# END mxcentral-for-iRedmail managed DKIM keys",
        ])

    def test_upgrade_preserves_existing_fallback(self):
        fallback = "@dkim_signature_options_bysender_maps = ({ '.' => { d => 'relay.example.net' } });\n"
        helper.validate_managed_change(helper.DEFAULT_CONFIG, 'amavis_config',
                                      fallback + self.block('push'), fallback + self.block('unshift'))

    def test_legacy_blocks_remain_accepted(self):
        helper.validate_amavis_dkim_block(helper.DEFAULT_CONFIG, self.block('push'))

    def test_arbitrary_perl_remains_rejected(self):
        with self.assertRaises(helper.RequestError):
            helper.validate_amavis_dkim_block(helper.DEFAULT_CONFIG, self.block('system'))

    def test_outside_fallback_cannot_be_changed(self):
        with self.assertRaises(helper.RequestError):
            helper.validate_managed_change(helper.DEFAULT_CONFIG, 'amavis_config',
                                          "# unchanged\n" + self.block('push'),
                                          "# modified\n" + self.block('unshift'))

    def test_duplicate_maps_are_rejected(self):
        content = self.block('unshift').replace('};', '};\nunshift @dkim_signature_options_bysender_maps, {\n};')
        with self.assertRaises(helper.RequestError):
            helper.validate_amavis_dkim_block(helper.DEFAULT_CONFIG, content)
