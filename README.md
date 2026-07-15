# eGov Law Monitor

eGov Law Monitor is an open-source project that monitors official Japanese legal information, transforms it into a canonical data model, and publishes structured JSON data through GitHub Pages.

Currently supported sources:

- e-Gov Law Updates
- e-Gov Public Comments

The project is designed around a common Event model, making it easy to integrate additional official government information sources in the future.

Planned future sources include:

- e-Gov Data Portal
- Cabinet Legislation Bureau ("Recently Enacted Laws")
- Other official legal information services

---

## Features

- Automatic monitoring of e-Gov Law Updates
- Automatic retrieval of e-Gov Public Comments
- GitHub Pages dashboard
- Email notifications
- Source-specific statistics
- Canonical Event model
- Public Law model (`laws.json`)
- Multi-source architecture

---

## GitHub Pages

The published website currently provides:

- Dashboard
- Law Updates
- Public Comments

https://fumizoh.github.io/egov-law-monitor/

---

## Screenshots

Screenshots will be updated in a future release.

---

## Directory Structure

```text
.
├── .github/
│   └── workflows/
│       └── check.yml
│
├── docs/
│   ├── css/
│   ├── data/
│   │   ├── app.json
│   │   ├── egov_updates.json
│   │   ├── keywords.json
│   │   ├── laws.json
│   │   ├── public_comments.json
│   │   └── statistics.json
│   │
│   ├── js/
│   │   ├── dashboard.js
│   │   ├── law-updates.js
│   │   ├── public-comments.js
│   │   └── utils.js
│   │
│   ├── index.html
│   ├── law-updates.html
│   └── public-comments.html
│
├── src/
│   ├── sources/
│   │   ├── egov.py
│   │   └── public_comment.py
│   │
│   ├── config.py
│   ├── email_generator.py
│   ├── egov_bulk.py
│   ├── law_view.py
│   ├── mailer.py
│   ├── models.py
│   ├── monitor.py
│   ├── pipeline.py
│   ├── statistics.py
│   ├── storage.py
│   └── update_parser.py
│
├── CHANGELOG.md
├── LICENSE
├── README.md
└── VERSION
```

---

## Architecture

```text
Official Sources
        │
        ▼
  Canonical Event Model
        │
        ├── Statistics
        ├── Email Notification
        ├── JSON Export
        │
        ▼
   Public Law Model
        │
        ▼
 GitHub Pages / External Applications
```

---

## Public Data

The project publishes structured JSON data under `docs/data`.

| File | Description |
|------|-------------|
| `app.json` | Application metadata |
| `statistics.json` | Dashboard statistics |
| `egov_updates.json` | Canonical Event data for e-Gov law updates |
| `public_comments.json` | Canonical Event data for Public Comments |
| `laws.json` | Public Law model for GitHub Pages and external applications |
| `keywords.json` | Highlight keywords |

The Event model is the canonical internal representation.

The Law model is generated from Event data and is intended for GitHub Pages, WordPress, and future external integrations.

---

## Workflow

GitHub Actions runs every day and performs:

1. Download e-Gov Law Updates
2. Extract ZIP archive
3. Parse CSV
4. Retrieve Public Comments
5. Build canonical Event data
6. Generate public JSON files
7. Publish GitHub Pages
8. Send email notifications

---

## Email Notifications

Notification emails are sent only when updates are available.

(Currently only e-Gov Law Updates trigger notifications.)

Each email includes:

- Update date
- Number of updates
- Updated laws
- Keyword highlighting
- GitHub Pages link

---

## Technologies

- Python 3.13
- GitHub Actions
- GitHub Pages
- HTML
- CSS
- JavaScript
- SMTP

---

## Roadmap

- e-Gov Data Portal support
- Cabinet Legislation Bureau integration
- Richer law metadata
- Improved Law UI
- Search and filtering
- Additional official information sources

---

## License

MIT License