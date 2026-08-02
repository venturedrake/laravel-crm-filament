@once
    <style>
        .crm-card-area-notes,
        .crm-card-area-tasks,
        .crm-card-area-calls,
        .crm-card-area-meetings,
        .crm-card-area-lunches,
        .crm-card-area-files,
        .crm-card-area-activity {
            --crm-card-bg: #ffffff;
            --crm-card-border: rgba(0, 0, 0, 0.06);
            --crm-card-text: #111827;
            --crm-card-muted: #6b7280;
            --crm-card-subtle: #9ca3af;
            --crm-card-pill-bg: rgba(0, 0, 0, 0.05);
            --crm-card-pill-color: #374151;
            --crm-card-input-bg: #ffffff;
            --crm-card-input-border: rgba(15, 23, 42, 0.1);
            --crm-card-input-color: #111827;
            --crm-card-primary: #05b3a9;
            --crm-card-primary-hover: #047d75;
            --crm-card-danger: #dc2626;

            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        html.dark .crm-card-area-notes,
        html.dark .crm-card-area-tasks,
        html.dark .crm-card-area-calls,
        html.dark .crm-card-area-meetings,
        html.dark .crm-card-area-lunches,
        html.dark .crm-card-area-files,
        html.dark .crm-card-area-activity {
            --crm-card-bg: var(--color-gray-900, rgb(17, 24, 39));
            --crm-card-border: rgba(255, 255, 255, 0.1);
            --crm-card-text: #ffffff;
            --crm-card-muted: #9ca3af;
            --crm-card-subtle: #6b7280;
            --crm-card-pill-bg: rgba(255, 255, 255, 0.05);
            --crm-card-pill-color: #d1d5db;
            --crm-card-input-bg: rgba(255, 255, 255, 0.05);
            --crm-card-input-border: rgba(255, 255, 255, 0.2);
            --crm-card-input-color: #ffffff;
            --crm-card-primary: #2dd4bf;
            --crm-card-primary-hover: #0d9488;
            --crm-card-danger: #f87171;
        }
        .crm-card-card {
            position: relative;
            background: var(--crm-card-bg);
            border: 1px solid var(--crm-card-border);
            border-radius: 0.5rem;
            padding: 0.875rem 1rem;
            color: var(--crm-card-text);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }
        .crm-card-card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .crm-card-card-meta { font-size: 0.8125rem; font-weight: 600; color: var(--crm-card-muted); }
        .crm-card-card-body {
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 0.875rem;
            line-height: 1.45;
            margin-bottom: 0.5rem;
        }
        .crm-card-card-footer { margin-top: 0.5rem; }
        .crm-card-pill {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            background: var(--crm-card-pill-bg);
            color: var(--crm-card-pill-color);
            font-size: 0.6875rem;
            font-weight: 600;
        }
        .crm-card-empty {
            color: var(--crm-card-muted);
            font-size: 0.875rem;
            text-align: center;
            padding: 1.5rem 1rem;
        }
        .crm-card-form { display: flex; flex-direction: column; gap: 0.5rem; }
        .crm-card-textarea {
            width: 100%;
            min-height: 90px;
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            border: 1px solid var(--crm-card-input-border);
            background: var(--crm-card-input-bg);
            color: var(--crm-card-input-color);
            font-size: 0.875rem;
            line-height: 1.5;
            resize: vertical;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            transition: box-shadow 75ms, border-color 75ms;
        }
        .crm-card-textarea:focus {
            outline: none;
            border-color: var(--crm-card-primary);
            box-shadow: 0 0 0 1px var(--crm-card-primary);
        }
        .crm-card-textarea::placeholder { color: var(--crm-card-subtle); }
        .crm-card-noted-at {
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            border: 1px solid var(--crm-card-input-border);
            background: var(--crm-card-input-bg);
            color: var(--crm-card-input-color);
            font-size: 0.875rem;
            line-height: 1.5;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            transition: box-shadow 75ms, border-color 75ms;
        }
        .crm-card-noted-at:focus {
            outline: none;
            border-color: var(--crm-card-primary);
            box-shadow: 0 0 0 1px var(--crm-card-primary);
        }
        .crm-card-form-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .crm-card-section-heading {
            font-size: 1rem;
            font-weight: 600;
            color: var(--crm-card-text);
            margin: 0 0 0.75rem;
        }
        .crm-card-section-divider {
            border: 0;
            border-top: 1px solid var(--crm-card-border);
            margin: 0 -1rem 0.75rem;
        }
        .crm-card-section-divider--footer {
            margin: 0.75rem -1rem 0.75rem;
        }
        .crm-card-field { display: flex; flex-direction: column; gap: 0.25rem; }
        .crm-card-field-label {
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--crm-card-text);
        }
        .crm-card-btn {
            padding: 0.4375rem 0.875rem;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-weight: 600;
            border: 1px solid var(--crm-card-input-border);
            background: transparent;
            color: var(--crm-card-text);
            cursor: pointer;
        }
        .crm-card-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .crm-card-btn--primary {
            background: var(--crm-card-primary);
            border-color: var(--crm-card-primary);
            color: #ffffff;
        }
        .crm-card-btn--primary:hover { background: var(--crm-card-primary-hover); }
        .crm-card-dropdown { position: relative; }
        .crm-card-dropdown-btn {
            background: transparent;
            border: 0;
            color: var(--crm-card-muted);
            cursor: pointer;
            font-size: 1.125rem;
            line-height: 1;
            padding: 0.125rem 0.375rem;
            border-radius: 0.25rem;
        }
        .crm-card-dropdown-btn:hover { color: var(--crm-card-text); background: var(--crm-card-pill-bg); }
        .crm-card-dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 0.25rem;
            min-width: 140px;
            background: var(--crm-card-bg);
            border: 1px solid var(--crm-card-border);
            border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
            z-index: 10;
            overflow: hidden;
        }
        .crm-card-dropdown-item {
            display: block;
            width: 100%;
            text-align: left;
            padding: 0.5rem 0.75rem;
            background: transparent;
            border: 0;
            color: var(--crm-card-text);
            font-size: 0.8125rem;
            cursor: pointer;
        }
        .crm-card-dropdown-item:hover { background: var(--crm-card-pill-bg); }
        .crm-card-dropdown-item--danger { color: var(--crm-card-danger); }
        .crm-card-card-title {
            font-size: 1.0625rem;
            font-weight: 700;
            color: var(--crm-card-text);
            line-height: 1.3;
        }
        .crm-card-badges {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            gap: 0.375rem;
            margin-top: 0.25rem;
            margin-bottom: 0.5rem;
        }
        .crm-card-badge {
            display: inline-block;
            padding: 0.1875rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1.2;
        }
        .crm-card-badge--success {
            background: #10b981;
            color: #ffffff;
        }
        .crm-card-badge--primary {
            background: var(--crm-card-primary);
            color: #ffffff;
        }
        .crm-card-badge--related {
            background: #f59e0b;
            color: #ffffff;
        }
        html.dark .crm-card-badge--related {
            background: #fbbf24;
            color: #451a03;
        }
        html.dark .crm-card-badge--success {
            background: #14b8a6;
            color: #052e2b;
        }
        html.dark .crm-card-badge--primary {
            background: var(--crm-card-primary);
            color: #052e2b;
        }
        .crm-card-card-attribution {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
            color: var(--crm-card-muted);
        }
        .crm-card-attribution-name {
            color: var(--crm-card-primary);
            font-weight: 500;
        }
        html.dark .crm-card-attribution-name {
            color: var(--crm-card-primary);
        }
        .crm-card-attribution-sep {
            color: var(--crm-card-muted);
        }
        .crm-card-row-2 {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.5rem;
        }
        @media (min-width: 640px) {
            .crm-card-row-2 { grid-template-columns: 1fr 1fr; }
        }
        .crm-card-section-divider--inset {
            margin: 0.75rem -1rem;
        }
        .crm-card-section-title {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--crm-card-text);
            margin: 0 0 0.5rem;
        }
        .crm-card-section-content {
            font-size: 0.875rem;
            color: var(--crm-card-text);
            white-space: pre-wrap;
            word-break: break-word;
        }
        .crm-card-guests {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            gap: 0.75rem 1rem;
            align-items: center;
        }
        .crm-card-guest-item {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.875rem;
        }
        .crm-card-guest-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.25rem;
            height: 1.25rem;
            color: var(--crm-card-subtle);
        }
        .crm-card-guest-link {
            color: var(--crm-card-primary);
            font-weight: 500;
        }
        .crm-card-card-title-link {
            color: var(--crm-card-primary);
            text-decoration: none;
            font-weight: 700;
        }
        .crm-card-card-title-link:hover { text-decoration: underline; }
        .crm-timeline-item {
            display: flex;
            flex-direction: row;
            gap: 0.75rem;
            position: relative;
        }
        .crm-timeline-rail {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 1rem;
            flex-shrink: 0;
            padding-top: 0.25rem;
        }
        .crm-timeline-bullet {
            display: block;
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 9999px;
            background: var(--crm-card-primary);
            flex-shrink: 0;
        }
        .crm-timeline-connector {
            display: block;
            width: 2px;
            flex: 1;
            background: var(--crm-card-border);
            margin-top: 0.25rem;
            min-height: 1.5rem;
        }
        .crm-timeline-body {
            flex: 1;
            padding-bottom: 1.25rem;
        }
        .crm-timeline-item--last .crm-timeline-body {
            padding-bottom: 0;
        }
        .crm-timeline-title {
            font-weight: 600;
            color: var(--crm-card-text);
            font-size: 0.9375rem;
        }
        .crm-timeline-subtitle {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--crm-card-muted);
            margin-top: 0.125rem;
        }
        .crm-timeline-recordable {
            margin-top: 0.5rem;
        }
        .crm-timeline-recordable-title {
            font-weight: 600;
            color: var(--crm-card-text);
            font-size: 0.875rem;
        }
        .crm-timeline-recordable-body {
            font-size: 0.875rem;
            color: var(--crm-card-text);
            white-space: pre-wrap;
            word-break: break-word;
            margin-top: 0.25rem;
        }
    </style>
@endonce
