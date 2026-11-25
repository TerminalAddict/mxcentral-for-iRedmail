import copy
import importlib.machinery
import unittest
from pathlib import Path
from unittest.mock import patch

helper = importlib.machinery.SourceFileLoader(
    'send_only_helper', str(Path(__file__).resolve().parents[2] / 'scripts' / 'mxcentral-privileged'),
).load_module()


class SendOnlyLoginsTest(unittest.TestCase):
    def setUp(self):
        self.config = copy.deepcopy(helper.DEFAULT_CONFIG)
        self.original = ('smtpd_sender_restrictions = reject_non_fqdn_sender reject_unlisted_sender '
                         'permit_mynetworks permit_sasl_authenticated reject_unknown_sender_domain\n'
                         'smtpd_reject_unlisted_sender = yes\n'
                         'smtpd_recipient_restrictions = reject_unauth_destination\n')
        self.updated = self.original.replace('reject_unlisted_sender', helper.login_sender_hook(self.config) + ', reject_unlisted_sender', 1).replace('= yes', '= no')
        self.settings = "ALLOWED_LOGIN_MISMATCH_SENDERS = ['sender@example.com']\n"
        self.mapping = helper.send_only_login_map(['sender@example.com'])

    def test_safe_upgrade_and_revocation(self):
        helper.validate_managed_change(self.config, 'postfix_main', self.original, self.updated)
        helper.validate_send_only_guard(self.config, self.updated)
        self.assertNotIn(' OK', helper.send_only_login_map([]))

    def test_cannot_weaken_guard_or_change_other_restrictions(self):
        for candidate in [self.updated.replace('reject_unlisted_sender,', ''),
                          self.updated.replace('reject_unlisted_sender ', ''),
                          self.updated.replace('reject_unauth_destination', 'permit'),
                          self.updated.replace('= no', '= yes'),
                          self.updated.replace('reject_non_fqdn_sender', 'permit_mynetworks')]:
            if candidate == self.updated:
                continue
            with self.assertRaises(helper.RequestError):
                helper.validate_managed_change(self.config, 'postfix_main', self.original, candidate)

    def test_permission_parser_rejects_wildcards_and_dynamic_assignments(self):
        for value in ["['@example.com']", "['*']", "get_logins()", "['sender@example.com', 1]"]:
            with self.assertRaises((helper.RequestError, ValueError)):
                helper.approved_mismatch_logins('ALLOWED_LOGIN_MISMATCH_SENDERS = ' + value)
        with self.assertRaises(helper.RequestError):
            helper.approved_mismatch_logins(self.settings + self.settings)
        self.assertEqual(['sender@example.com'], helper.approved_mismatch_logins(self.settings))

    def prepare(self, writes, commands):
        files = {self.config['files']['postfix_main']: self.updated.encode(),
                 self.config['files']['iredapd_settings']: self.settings.encode(),
                 self.config['files']['postfix_login_sender_access']: self.mapping.encode()}
        with patch.object(helper, 'secure_regular_file', return_value=True), patch.object(helper, 'read_nofollow', side_effect=files.__getitem__):
            return helper.prepare_configuration(self.config, {'writes': writes, 'commands': commands})

    def test_map_cannot_grant_an_unselected_login(self):
        with self.assertRaises(helper.RequestError):
            self.prepare({'postfix_login_sender_access': helper.send_only_login_map(['other@example.com'])}, ['postfix_check', 'postfix_reload'])

    def test_map_requires_check_and_reload(self):
        with self.assertRaises(helper.RequestError):
            self.prepare({'postfix_login_sender_access': self.mapping}, [])
        self.prepare({'postfix_login_sender_access': self.mapping}, ['postfix_check', 'postfix_reload'])

    def test_revocation_must_update_both_files(self):
        # Cross-file invariant rejects stale permissions even if individual file validation passed.
        with patch.object(helper, 'validate_managed_change'):
            with self.assertRaises(helper.RequestError):
                self.prepare({'iredapd_settings': 'ALLOWED_LOGIN_MISMATCH_SENDERS = []\n'}, ['iredapd_restart'])
            self.prepare({'iredapd_settings': 'ALLOWED_LOGIN_MISMATCH_SENDERS = []\n',
                          'postfix_login_sender_access': helper.send_only_login_map([])},
                         ['iredapd_restart', 'postfix_check', 'postfix_reload'])
