-- MXCentral least-privilege MariaDB/MySQL example.
-- Replace every CHANGE_ME value with a different strong secret before use.

CREATE USER IF NOT EXISTS 'mxcentral_vmail'@'localhost' IDENTIFIED BY 'CHANGE_ME_VMAIL';
CREATE USER IF NOT EXISTS 'mxcentral_iredadmin'@'localhost' IDENTIFIED BY 'CHANGE_ME_IREDADMIN';
CREATE USER IF NOT EXISTS 'mxcentral_amavisd'@'localhost' IDENTIFIED BY 'CHANGE_ME_AMAVISD';
CREATE USER IF NOT EXISTS 'mxcentral_iredapd'@'localhost' IDENTIFIED BY 'CHANGE_ME_IREDAPD';
CREATE USER IF NOT EXISTS 'mxcentral_fail2ban'@'localhost' IDENTIFIED BY 'CHANGE_ME_FAIL2BAN';

GRANT SELECT, INSERT, UPDATE, DELETE ON vmail.admin TO 'mxcentral_vmail'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON vmail.alias TO 'mxcentral_vmail'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON vmail.alias_domain TO 'mxcentral_vmail'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON vmail.domain TO 'mxcentral_vmail'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON vmail.domain_admins TO 'mxcentral_vmail'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON vmail.forwardings TO 'mxcentral_vmail'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON vmail.mailbox TO 'mxcentral_vmail'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON vmail.maillist_owners TO 'mxcentral_vmail'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON vmail.maillists TO 'mxcentral_vmail'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON vmail.moderators TO 'mxcentral_vmail'@'localhost';

GRANT INSERT ON iredadmin.log TO 'mxcentral_iredadmin'@'localhost';
GRANT INSERT ON iredadmin.deleted_mailboxes TO 'mxcentral_iredadmin'@'localhost';

GRANT SELECT, DELETE ON amavisd.msgs TO 'mxcentral_amavisd'@'localhost';
GRANT SELECT, DELETE ON amavisd.quarantine TO 'mxcentral_amavisd'@'localhost';
GRANT SELECT ON amavisd.msgrcpt TO 'mxcentral_amavisd'@'localhost';
GRANT SELECT ON amavisd.maddr TO 'mxcentral_amavisd'@'localhost';
GRANT SELECT, INSERT ON amavisd.mailaddr TO 'mxcentral_amavisd'@'localhost';
GRANT SELECT, INSERT, UPDATE ON amavisd.wblist TO 'mxcentral_amavisd'@'localhost';

GRANT SELECT, INSERT, UPDATE ON iredapd.throttle TO 'mxcentral_iredapd'@'localhost';

GRANT SELECT, UPDATE ON fail2ban.banned TO 'mxcentral_fail2ban'@'localhost';

-- Optional: required only when the decryptable-password schema toggle remains
-- application-managed from System Settings.
-- GRANT ALTER ON vmail.mailbox TO 'mxcentral_vmail'@'localhost';

FLUSH PRIVILEGES;
