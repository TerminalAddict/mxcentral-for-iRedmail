<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1a73e8">
    <title>{{ config('app.name', 'MXCentral Mail Admin') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <style>
        :root {
            color-scheme: light;
            --app-bg: #f1f3f4;
            --surface: #ffffff;
            --surface-alt: #f8fafd;
            --border: #dadce0;
            --border-strong: #c2c8d0;
            --text: #202124;
            --muted: #5f6368;
            --accent: #1a73e8;
            --accent-strong: #1558b0;
            --accent-soft: #e8f0fe;
            --success: #188038;
            --warning: #b06000;
            --danger: #c5221f;
            --shadow-soft: 0 1px 2px rgba(32, 33, 36, 0.1), 0 1px 3px rgba(32, 33, 36, 0.08);
            --shadow-raise: 0 8px 24px rgba(32, 33, 36, 0.12);
            --radius-sm: 12px;
            --radius-md: 18px;
            --radius-lg: 24px;
            --topbar-height: 64px;
            --mobile-nav-height: 72px;
            --content-width: 1240px;
            --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            --sans: "Google Sans", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--app-bg);
            color: var(--text);
            font-family: var(--sans);
            font-size: 14px;
            overflow-x: clip;
        }
        a { color: inherit; text-decoration: none; }
        a:hover { text-decoration: none; }
        button, input, textarea, select { font: inherit; }
        :focus-visible { outline: 3px solid rgba(26, 115, 232, 0.35); outline-offset: 2px; }

        .app-shell { min-height: 100vh; }
        .app-topbar {
            position: sticky;
            top: 0;
            z-index: 60;
            background: rgba(241, 243, 244, 0.92);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(218, 220, 224, 0.85);
        }
        .app-topbar__inner {
            display: flex;
            align-items: center;
            gap: 16px;
            width: min(var(--content-width), calc(100% - 24px));
            min-height: var(--topbar-height);
            margin: 0 auto;
        }
        .app-brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            color: var(--text);
        }
        .app-brand__mark, .brand__mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 12px;
            background: linear-gradient(145deg, var(--accent), #5f9cff);
            color: #fff;
            box-shadow: 0 6px 18px rgba(26, 115, 232, 0.28);
            flex: 0 0 auto;
        }
        .brand__mark { width: 40px; height: 40px; border-radius: 14px; }
        .app-brand__text, .brand__text { display: grid; gap: 2px; min-width: 0; }
        .app-brand__title, .brand__title { font-size: 1rem; font-weight: 700; letter-spacing: -0.01em; white-space: nowrap; }
        .app-brand__subtitle, .brand__subtitle { color: var(--muted); font-size: 0.74rem; white-space: nowrap; }

        .app-primary-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
            min-width: 0;
        }
        .app-primary-nav__link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 42px;
            padding: 0 14px;
            border-radius: 999px;
            color: var(--muted);
            font-weight: 600;
            white-space: nowrap;
        }
        .app-primary-nav__link:hover { background: rgba(60, 64, 67, 0.06); color: var(--text); }
        .app-primary-nav__link.is-active { background: var(--accent-soft); color: var(--accent-strong); font-weight: 800; }
        .app-primary-nav__icon { width: 18px; height: 18px; flex: 0 0 auto; }
        .app-primary-nav__link--compact { padding: 0 12px; }
        .app-primary-nav__group {
            position: relative;
            display: inline-flex;
        }
        .app-primary-nav__group::after {
            content: "";
            position: absolute;
            top: 100%;
            right: 0;
            left: 0;
            height: 10px;
        }
        .app-menu-button {
            min-height: 42px;
            padding: 0 14px;
            background: #fff;
            color: var(--accent-strong);
            border-color: var(--border);
        }
        .app-menu-button:hover,
        .app-primary-nav__group:focus-within .app-menu-button {
            background: var(--accent-soft);
        }
        .app-menu {
            position: absolute;
            top: calc(100% + 4px);
            right: 0;
            z-index: 120;
            display: grid;
            gap: 4px;
            min-width: 220px;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.98);
            box-shadow: var(--shadow-raise);
            opacity: 0;
            pointer-events: none;
            transform: translateY(-4px);
            transition: opacity 120ms ease, transform 120ms ease;
        }
        .app-primary-nav__group:hover .app-menu,
        .app-primary-nav__group:focus-within .app-menu {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0);
        }
        .app-menu__link {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 40px;
            padding: 0 12px;
            border-radius: 14px;
            color: var(--muted);
            font-weight: 700;
        }
        .app-menu__link:hover,
        .app-menu__link.is-active {
            background: var(--accent-soft);
            color: var(--accent-strong);
        }
        .app-menu__icon {
            width: 18px;
            height: 18px;
            flex: 0 0 auto;
        }
        .logout-form { margin: 0; }

        .app-main {
            width: min(var(--content-width), calc(100% - 24px));
            margin: 0 auto;
            padding: 24px 0 40px;
        }
        h1 {
            margin: 0 0 18px;
            font-size: clamp(1.7rem, 3vw, 2.35rem);
            line-height: 1.08;
            letter-spacing: -0.03em;
        }
        h2 { margin: 0 0 14px; font-size: 1.1rem; font-weight: 700; }
        p, li { margin: 0; color: var(--muted); line-height: 1.65; }
        pre, code, .mono { font-family: var(--mono); }
        pre { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.55; }

        .panel, .stat, table {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-soft);
        }
        .panel { padding: 20px; margin-bottom: 18px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 18px; margin-bottom: 18px; }
        .stat { padding: 18px; }
        .stat strong { display: block; margin-top: 6px; font-size: 2rem; letter-spacing: -0.03em; }
        .muted { color: var(--muted); }
        .ok { color: var(--success); font-weight: 800; }
        .bad { color: var(--danger); font-weight: 800; }

        .toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
        .toolbar form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 0; }
        .toolbar input { width: min(280px, 100%); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; align-items: end; }
        form { margin: 0; }
        label { display: grid; gap: 8px; color: var(--text); font-size: 0.88rem; font-weight: 700; }
        .field-hint {
            display: block;
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 500;
            line-height: 1.45;
        }
        .checkbox-field {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            min-height: 44px;
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
        }
        .checkbox-field input[type="checkbox"] {
            flex: 0 0 auto;
            width: 18px;
            height: 18px;
            margin: 2px 0 0;
        }
        .checkbox-field__body { display: grid; gap: 4px; min-width: 0; }
        .checkbox-field__label { color: var(--text); font-weight: 800; line-height: 1.2; }
        input, select, textarea {
            width: 100%;
            min-height: 44px;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 8px 12px;
            background: #fff;
            color: var(--text);
        }
        textarea { min-height: 92px; resize: vertical; }
        input[type="checkbox"] { min-height: 0; accent-color: var(--accent); }
        input:focus-visible, select:focus-visible, textarea:focus-visible { border-color: var(--accent); }

        button, .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 0 18px;
            border: 1px solid transparent;
            border-radius: 999px;
            background: var(--accent);
            color: #fff;
            font-size: 0.92rem;
            font-weight: 800;
            cursor: pointer;
            white-space: nowrap;
        }
        button:hover, .button:hover { background: var(--accent-strong); }
        .button.secondary, button.secondary {
            background: #fff;
            color: var(--accent-strong);
            border-color: var(--border);
        }
        .button.secondary:hover, button.secondary:hover { background: var(--accent-soft); }
        .button.danger, button.danger { background: rgba(197, 34, 31, 0.1); color: var(--danger); border-color: rgba(197, 34, 31, 0.22); }
        .button.danger:hover, button.danger:hover { background: rgba(197, 34, 31, 0.16); }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            overflow: hidden;
            margin-bottom: 18px;
        }
        thead th {
            background: var(--surface-alt);
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        th, td {
            padding: 12px 14px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid rgba(218, 220, 224, 0.85);
            word-break: break-word;
        }
        tbody tr:last-child td { border-bottom: 0; }
        td form + form { margin-top: 8px; }
        .domain-summary-table th:nth-child(2),
        .domain-summary-table td:nth-child(2) { width: 190px; }
        .domain-summary-table th:nth-child(3),
        .domain-summary-table td:nth-child(3) { width: 260px; }
        .domain-summary-table th:nth-child(4),
        .domain-summary-table td:nth-child(4) { width: 280px; }
        .domain-summary-table th:nth-child(5),
        .domain-summary-table td:nth-child(5) { width: 130px; }
        .page-titlebar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }
        .page-titlebar h1 { margin: 0; }
        .search-compact {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 1 420px;
        }
        .search-compact input { min-width: 0; }
        .domain-form { display: grid; gap: 16px; }
        .domain-form__grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            align-items: start;
        }
        .domain-form__grid .span-2 { grid-column: span 2; }
        .domain-form__footer {
            display: flex;
            align-items: stretch;
            justify-content: space-between;
            gap: 14px;
            padding-top: 2px;
        }
        .domain-form__footer .checkbox-field {
            flex: 1 1 360px;
            max-width: 460px;
        }
        .domain-form__footer button {
            align-self: stretch;
            min-width: 230px;
        }
        .domain-selector-row {
            display: grid;
            grid-template-columns: minmax(280px, 420px) 180px;
            gap: 12px;
            align-items: start;
            margin-bottom: 18px;
            justify-content: start;
        }
        .domain-selector-row button {
            margin-top: 25px;
        }
        .domain-edit-actions {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 220px;
            gap: 14px;
            align-items: stretch;
        }
        .domain-edit-actions button { align-self: stretch; }
        .domain-dkim {
            display: grid;
            gap: 14px;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
        }
        .domain-dkim h3 {
            margin: 0 0 4px;
            font-size: 1rem;
        }
        .domain-dkim__header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        .domain-dkim__status,
        .domain-dkim__actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .domain-dkim__status span {
            min-height: 30px;
            padding: 6px 10px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: #fff;
            font-size: 0.78rem;
        }
        .domain-dkim__meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            margin: 0;
        }
        .domain-dkim__meta div {
            min-width: 0;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-alt);
        }
        .domain-dkim__meta dt {
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
        }
        .domain-dkim__meta dd {
            margin: 5px 0 0;
            overflow-wrap: anywhere;
        }
        .domain-dkim__dns {
            display: grid;
            gap: 8px;
        }
        .domain-dkim__dns pre {
            max-height: 180px;
            overflow: auto;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-alt);
        }
        .domain-dns-table {
            table-layout: fixed;
        }
        .domain-dns-table th:nth-child(1),
        .domain-dns-table td:nth-child(1) { width: 90px; }
        .domain-dns-table th:nth-child(2),
        .domain-dns-table td:nth-child(2) { width: 230px; }
        .domain-dns-table th:nth-child(3),
        .domain-dns-table td:nth-child(3) { width: 120px; }
        .domain-dns-table td:nth-child(2),
        .domain-dns-table td:nth-child(4) {
            overflow-wrap: anywhere;
        }
        .domain-dns-table pre {
            white-space: pre-wrap;
            word-break: break-word;
            margin: 0 0 8px;
        }
        .domain-dns-table pre:last-child { margin-bottom: 0; }
        .domain-danger-row {
            display: grid;
            grid-template-columns: minmax(180px, 260px) 260px;
            gap: 14px;
            align-items: start;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            justify-content: start;
        }
        .domain-danger-row button {
            min-height: 44px;
            margin-top: 25px;
        }
        .user-form { display: grid; gap: 16px; }
        .user-form__grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            align-items: start;
        }
        .user-form__grid .span-2 { grid-column: span 2; }
        .user-form__grid .span-4 { grid-column: 1 / -1; }
        .user-forwarding-form {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
        }
        .user-selector-row {
            display: grid;
            grid-template-columns: minmax(320px, 520px) 180px;
            gap: 12px;
            align-items: start;
            justify-content: start;
            margin-bottom: 18px;
        }
        .user-selector-row button { margin-top: 25px; }
        .user-service-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(110px, 1fr));
            gap: 10px;
        }
        .user-actions-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 220px;
            gap: 14px;
            align-items: stretch;
        }
        .user-actions-row button { align-self: stretch; }
        .user-danger-row {
            display: grid;
            grid-template-columns: minmax(180px, 260px) 220px;
            gap: 14px;
            align-items: start;
            justify-content: start;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }
        .user-danger-row button {
            min-height: 44px;
            margin-top: 25px;
        }
        .user-summary-table th:nth-child(2),
        .user-summary-table td:nth-child(2) { width: 190px; }
        .user-summary-table th:nth-child(3),
        .user-summary-table td:nth-child(3) { width: 150px; }
        .user-summary-table th:nth-child(4),
        .user-summary-table td:nth-child(4) { width: 280px; }
        .user-summary-table th:nth-child(5),
        .user-summary-table td:nth-child(5) { width: 120px; }
        .record-form { display: grid; gap: 16px; }
        .record-form__grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            align-items: start;
        }
        .record-form__grid .span-2 { grid-column: span 2; }
        .record-form__grid .span-3 { grid-column: span 3; }
        .record-form__grid .span-4 { grid-column: 1 / -1; }
        .record-form__footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .record-form__footer button,
        .record-form__footer .button { min-width: 180px; }
        .record-selector-row {
            display: grid;
            grid-template-columns: minmax(260px, 420px) 150px;
            gap: 12px;
            align-items: start;
            justify-content: start;
            margin-bottom: 18px;
        }
        .record-selector-row button { margin-top: 25px; }
        .record-actions-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 200px;
            gap: 14px;
            align-items: stretch;
        }
        .record-actions-row button { align-self: stretch; }
        .record-danger-row {
            display: grid;
            grid-template-columns: minmax(220px, 360px) 200px;
            gap: 14px;
            align-items: start;
            justify-content: start;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }
        .record-danger-row button {
            min-height: 44px;
            margin-top: 25px;
        }
        .record-danger-row--compact button { margin-top: 0; }
        .summary-table th:nth-child(1),
        .summary-table td:nth-child(1) { width: 260px; }
        .summary-table.domain-dns-table th:nth-child(1),
        .summary-table.domain-dns-table td:nth-child(1) { width: 90px; }
        .summary-table.domain-dns-table th:nth-child(2),
        .summary-table.domain-dns-table td:nth-child(2) { width: 230px; }
        .summary-table.domain-dns-table th:nth-child(3),
        .summary-table.domain-dns-table td:nth-child(3) { width: 120px; }
        .summary-table.domain-dns-table th:nth-child(4),
        .summary-table.domain-dns-table td:nth-child(4) { width: auto; }
        .admin-summary-table th:last-child,
        .admin-summary-table td:last-child { width: 320px; }
        .filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .filter-tabs .button { min-height: 38px; padding: 0 14px; }
        .filter-tabs .is-active {
            background: var(--accent);
            color: #fff;
            border-color: transparent;
        }
        .bulk-action-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 12px 0 18px;
        }
        .table-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .table-actions .button,
        .table-actions button {
            min-height: 36px;
            padding: 0 12px;
            font-size: 0.84rem;
        }
        .select-cell,
        .select-cell input { width: 18px; }
        .quarantine-summary-table th:nth-child(1),
        .quarantine-summary-table td:nth-child(1) { width: 46px; }
        .quarantine-summary-table th:nth-child(2),
        .quarantine-summary-table td:nth-child(2) { width: 150px; }
        .quarantine-summary-table th:nth-child(6),
        .quarantine-summary-table td:nth-child(6) { width: 95px; }
        .quarantine-summary-table th:nth-child(7),
        .quarantine-summary-table td:nth-child(7) { width: 90px; }
        .quarantine-summary-table th:last-child,
        .quarantine-summary-table td:last-child { width: 180px; }
        .pagination { margin: 14px 0 18px; }
        .checkboxes { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; }
        .checkboxes label { display: flex; align-items: center; gap: 8px; }
        .checkboxes input { width: auto; }
        .password-toggle-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 96px;
            gap: 8px;
            align-items: stretch;
        }
        .password-toggle-row button { min-height: 42px; padding: 0 12px; }
        .settings-picker {
            display: grid;
            gap: 10px;
        }
        .settings-picker__list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 10px;
            max-height: 420px;
            overflow: auto;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-alt);
        }
        .settings-picker__item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            min-width: 0;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: #fff;
        }
        .settings-picker__item input {
            flex: 0 0 auto;
            width: 18px;
            height: 18px;
            margin-top: 2px;
        }
        .settings-picker__body {
            display: grid;
            gap: 3px;
            min-width: 0;
        }
        .settings-picker__email {
            font-weight: 800;
            word-break: break-word;
        }
        .settings-domain-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .settings-domain-list span {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 0 10px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface-alt);
            font-size: 0.82rem;
            font-weight: 800;
        }
        .settings-image-preview {
            display: grid;
            gap: 8px;
            align-content: start;
        }
        .settings-image-preview__frame {
            display: grid;
            place-items: center;
            min-height: 94px;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: #fff;
        }
        .settings-image-preview img {
            max-width: 100%;
            max-height: 72px;
        }

        .alert {
            display: grid;
            gap: 4px;
            margin-bottom: 14px;
            padding: 14px 16px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-soft);
        }
        .alert.ok { background: rgba(24, 128, 56, 0.1); border: 1px solid rgba(24, 128, 56, 0.22); color: var(--success); }
        .alert.bad { background: rgba(197, 34, 31, 0.1); border: 1px solid rgba(197, 34, 31, 0.22); color: var(--danger); }

        .login-page {
            min-height: calc(100vh - 48px);
            display: grid;
            place-items: center;
            padding: 24px 0;
        }
        .login-shell {
            width: min(960px, 100%);
            display: grid;
            gap: 24px;
            grid-template-columns: minmax(0, 1.1fr) minmax(320px, 420px);
            align-items: stretch;
        }
        .login-panel, .login-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-raise);
        }
        .login-panel { padding: 32px; }
        .login-card { padding: 28px; }
        .brand { display: inline-flex; align-items: center; gap: 12px; color: inherit; margin-bottom: 24px; }
        .login-panel h1 { font-size: clamp(2rem, 4vw, 2.8rem); }
        .login-highlights { margin-top: 24px; display: grid; gap: 14px; }
        .login-highlight { padding: 16px 18px; background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius-md); }
        .login-highlight strong { display: block; margin-bottom: 6px; }
        .button-row { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 4px; }
        .login-note { margin-top: 18px; font-size: 0.88rem; }

        .bottom-nav {
            position: fixed;
            right: 12px;
            bottom: 10px;
            left: 12px;
            z-index: 90;
            display: none;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            min-height: var(--mobile-nav-height);
            padding: 8px;
            border: 1px solid rgba(218, 220, 224, 0.9);
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.94);
            box-shadow: var(--shadow-raise);
            backdrop-filter: blur(14px);
        }
        .bottom-nav__link {
            display: grid;
            place-items: center;
            gap: 3px;
            min-width: 0;
            padding: 7px 4px;
            border-radius: 18px;
            color: var(--muted);
            font-size: 0.68rem;
            font-weight: 800;
        }
        .bottom-nav__link.is-active { background: var(--accent-soft); color: var(--accent-strong); }
        .bottom-nav__icon { width: 21px; height: 21px; }

        @media (max-width: 1120px) {
            .app-primary-nav__link { padding: 0 12px; }
        }
        @media (max-width: 860px) {
            .app-topbar__inner { min-height: 58px; }
            .app-primary-nav { display: none; }
            .app-main { padding-bottom: calc(var(--mobile-nav-height) + 28px); }
            .bottom-nav { display: grid; }
            table { display: block; overflow-x: auto; border-radius: var(--radius-md); }
            .panel { padding: 16px; border-radius: var(--radius-md); }
            .login-shell { grid-template-columns: 1fr; }
            .login-panel, .login-card { padding: 24px; }
        }
        @media (max-width: 640px) {
            body { font-size: 13px; }
            .app-brand__subtitle { display: none; }
            .app-main { width: min(100% - 16px, var(--content-width)); padding-top: 16px; }
            h1 { font-size: 1.65rem; }
            th, td { padding: 10px 12px; }
            .form-grid { grid-template-columns: 1fr; }
            .toolbar form, .toolbar input { width: 100%; }
            .page-titlebar { display: grid; }
            .search-compact { flex: none; width: 100%; }
            .domain-form__grid,
            .domain-selector-row,
            .domain-edit-actions,
            .domain-danger-row,
            .user-form__grid,
            .user-selector-row,
            .user-service-grid,
            .user-actions-row,
            .user-danger-row,
            .record-form__grid,
            .record-selector-row,
            .record-actions-row,
            .record-danger-row { grid-template-columns: 1fr; }
            .domain-selector-row button,
            .domain-danger-row button,
            .user-selector-row button,
            .user-danger-row button,
            .record-selector-row button,
            .record-danger-row button { margin-top: 0; }
            .domain-form__footer { display: grid; }
            .domain-form__footer .checkbox-field { max-width: none; }
            .domain-form__footer button { min-width: 0; }
            .user-form__grid .span-2,
            .user-form__grid .span-4,
            .record-form__grid .span-2,
            .record-form__grid .span-3,
            .record-form__grid .span-4 { grid-column: auto; }
            .record-form__footer { display: grid; }
            .record-form__footer button,
            .record-form__footer .button { min-width: 0; }
            .bulk-action-bar { display: grid; }
            .bottom-nav { right: 8px; left: 8px; bottom: 8px; }
        }
    </style>
</head>
<body>
@php
    $mailIcon = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5v-9Zm2.2.1v.3L12 12.1l5.8-4.2v-.3H6.2Zm11.6 1.8-5.3 3.8a.9.9 0 0 1-1 0L6.2 9.4v7.1c0 .2.1.4.3.4h11c.2 0 .3-.2.3-.4V9.4Z"></path></svg>';
    $icon = fn ($path) => '<svg class="app-primary-nav__icon bottom-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$path.'</svg>';
    $adminNav = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'match' => 'dashboard', 'icon' => $icon('<path d="M4 13h6V4H4v9Z"/><path d="M14 20h6V4h-6v16Z"/><path d="M4 20h6v-3H4v3Z"/>')],
        ['label' => 'Domains', 'route' => 'domains', 'match' => 'domains*', 'icon' => $icon('<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.2 2.5 3.3 5.5 3.3 9S14.2 18.5 12 21c-2.2-2.5-3.3-5.5-3.3-9S9.8 5.5 12 3Z"/>')],
        ['label' => 'Users', 'route' => 'users', 'match' => 'users*', 'icon' => $icon('<path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>')],
        ['label' => 'Aliases', 'route' => 'aliases', 'match' => 'aliases*', 'icon' => $icon('<path d="M4 4h7v7H4z"/><path d="M13 13h7v7h-7z"/><path d="M11 7h3a3 3 0 0 1 3 3v3"/><path d="m15 11 2 2 2-2"/>')],
        ['label' => 'Lists', 'route' => 'lists', 'match' => 'lists*', 'icon' => $icon('<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>')],
        ['label' => 'Mail', 'route' => 'mail.logs', 'params' => ['received'], 'match' => 'mail.logs', 'icon' => $icon('<path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/>')],
        ['label' => 'Quarantine', 'route' => 'quarantine', 'match' => 'quarantine*', 'icon' => $icon('<path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="M9 12h6"/>')],
        ['label' => 'Throttle', 'route' => 'throttle', 'match' => 'throttle*', 'icon' => $icon('<path d="M12 14a3 3 0 1 0-3-3"/><path d="M19.4 15a8 8 0 1 0-14.8 0"/><path d="m12 14 4-4"/>')],
        ['label' => 'Search', 'route' => 'search', 'match' => 'search', 'icon' => $icon('<circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/>')],
    ];
    if (session('actor.global_admin')) {
        $adminNav[] = ['label' => 'Admins', 'route' => 'admins', 'match' => 'admins*', 'icon' => $icon('<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21a8 8 0 0 1 16 0"/><path d="M19 8v4"/><path d="M21 10h-4"/>')];
        $adminNav[] = ['label' => 'Fail2ban', 'route' => 'fail2ban', 'match' => 'fail2ban*', 'icon' => $icon('<path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="m9.5 9.5 5 5"/><path d="m14.5 9.5-5 5"/>')];
        $adminNav[] = ['label' => 'System Settings', 'route' => 'system.settings', 'match' => 'system.settings*', 'icon' => $icon('<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2 3.4-.2-.1a1.7 1.7 0 0 0-2 .2 1.7 1.7 0 0 0-.8 1.6V22H9.2v-.2a1.7 1.7 0 0 0-.8-1.6 1.7 1.7 0 0 0-2-.2l-.2.1-2-3.4.1-.1A1.7 1.7 0 0 0 4.6 15 1.7 1.7 0 0 0 3 14H2v-4h1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 2-3.4.2.1a1.7 1.7 0 0 0 2-.2A1.7 1.7 0 0 0 9.2 2V2h5.6v.2a1.7 1.7 0 0 0 .8 1.6 1.7 1.7 0 0 0 2 .2l.2-.1 2 3.4-.1.1a1.7 1.7 0 0 0-.3 1.9A1.7 1.7 0 0 0 21 10h1v4h-1a1.7 1.7 0 0 0-1.6 1Z"/>')];
    }
    $selfNav = [
        ['label' => 'Preferences', 'route' => 'preferences', 'match' => 'preferences', 'icon' => $icon('<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2 3.4-.2-.1a1.7 1.7 0 0 0-2 .2 1.7 1.7 0 0 0-.8 1.6V22H9.2v-.2a1.7 1.7 0 0 0-.8-1.6 1.7 1.7 0 0 0-2-.2l-.2.1-2-3.4.1-.1A1.7 1.7 0 0 0 4.6 15 1.7 1.7 0 0 0 3 14H2v-4h1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 2-3.4.2.1a1.7 1.7 0 0 0 2-.2A1.7 1.7 0 0 0 9.2 2V2h5.6v.2a1.7 1.7 0 0 0 .8 1.6 1.7 1.7 0 0 0 2 .2l.2-.1 2 3.4-.1.1a1.7 1.7 0 0 0-.3 1.9A1.7 1.7 0 0 0 21 10h1v4h-1a1.7 1.7 0 0 0-1.6 1Z"/>')],
        ['label' => 'Quarantine', 'route' => 'quarantine', 'match' => 'quarantine*', 'icon' => $icon('<path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="M9 12h6"/>')],
    ];
    $navItems = session('actor.self_service') ? $selfNav : $adminNav;
    $desktopPrimaryRoutes = session('actor.self_service') ? ['preferences', 'quarantine'] : ['dashboard', 'domains', 'users', 'search'];
    $desktopPrimaryItems = array_values(array_filter($navItems, fn ($item) => in_array($item['route'], $desktopPrimaryRoutes, true)));
    $desktopMenuItems = array_values(array_filter($navItems, fn ($item) => ! in_array($item['route'], $desktopPrimaryRoutes, true)));
    $bottomItems = session('actor.self_service') ? $selfNav : array_values(array_filter($adminNav, fn ($item) => in_array($item['route'], ['dashboard', 'domains', 'users', 'mail.logs', 'search'], true)));
    $isActive = fn ($item) => request()->routeIs($item['match']);
@endphp
<div class="app-shell">
    @if(session('actor'))
        <header class="app-topbar">
            <div class="app-topbar__inner">
                <a class="app-brand" href="{{ route(session('actor.self_service') ? 'preferences' : 'dashboard') }}">
                    <span class="app-brand__mark">{!! $mailIcon !!}</span>
                    <span class="app-brand__text">
                        <span class="app-brand__title">MXCentral</span>
                        <span class="app-brand__subtitle">Mail admin</span>
                    </span>
                </a>
                <nav class="app-primary-nav" aria-label="Primary">
                    @foreach($desktopPrimaryItems as $item)
                        <a class="app-primary-nav__link app-primary-nav__link--compact @if($isActive($item)) is-active @endif" href="{{ route($item['route'], $item['params'] ?? []) }}">
                            {!! $item['icon'] !!}<span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                    @if(count($desktopMenuItems))
                        <div class="app-primary-nav__group">
                            <button class="app-menu-button" type="button" aria-haspopup="true" aria-expanded="false">
                                <svg class="app-primary-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>
                                Menu
                            </button>
                            <div class="app-menu" role="menu">
                                @foreach($desktopMenuItems as $item)
                                    <a class="app-menu__link @if($isActive($item)) is-active @endif" href="{{ route($item['route'], $item['params'] ?? []) }}" role="menuitem">
                                        {!! str_replace('app-primary-nav__icon bottom-nav__icon', 'app-menu__icon', $item['icon']) !!}<span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <form class="logout-form" method="post" action="{{ route('logout') }}">@csrf<button class="secondary">Logout</button></form>
                </nav>
            </div>
        </header>
    @endif
    <main class="app-main">
        @if(session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
        @if(isset($errors) && $errors->any())<div class="alert bad">{{ $errors->first() }}</div>@endif
        @yield('content')
    </main>
    @if(session('actor'))
        <nav class="bottom-nav" aria-label="Mobile primary">
            @foreach($bottomItems as $item)
                <a class="bottom-nav__link @if($isActive($item)) is-active @endif" href="{{ route($item['route'], $item['params'] ?? []) }}">
                    {!! $item['icon'] !!}<span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif
</div>
<script>
    (() => {
        const removeBlockingExtensionBars = () => {
            document.querySelectorAll('#bit-notification-bar-iframe, iframe[src^="chrome-extension://"][src*="/notification/bar.html"]').forEach((node) => node.remove());
        };

        removeBlockingExtensionBars();
        new MutationObserver(removeBlockingExtensionBars).observe(document.documentElement, { childList: true, subtree: true });
    })();
</script>
</body>
</html>
