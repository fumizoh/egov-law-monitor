# e-Gov Law Monitor

e-Gov Law Monitor is an open-source project that monitors official Japanese legal information, transforms it into a canonical data model, and publishes structured JSON data through GitHub Pages.

Currently supported source:

* e-Gov Law Updates

The project is designed around a common Event model, making it possible to integrate additional official government information sources in the future.

Planned future sources include:

* e-Gov Data Portal
* Cabinet Legislation Bureau ("Recently Enacted Laws")
* Other official legal information services

---

## Features

* Automatic monitoring of e-Gov Law Updates
* GitHub Pages dashboard
* AI-generated law amendment summaries
* Email notifications
* Law update statistics
* Canonical Event model
* Public Law model (`laws.json`)
* Multi-source architecture

---

## GitHub Pages

The published website currently provides:

* Dashboard
* Law Updates

https://fumizoh.github.io/egov-law-monitor/

---

## Screenshots

Screenshots will be updated in a future release.

---

## Directory Structure

```text
.
├── .github/
│   └── workflows/      # GitHub Actions
├── docs/                # GitHub Pages
├── src/                 # Application source code
├── tools/               # Development and inspection tools
├── CHANGELOG.md
├── LICENSE
├── README.md
└── VERSION
```

---

## Architecture

```text
e-Gov Law Updates
        │
        ▼
  Canonical Event Model
        │
        ├── Statistics
        ├── Email Notification
        │
        ▼
    Public Law Model
        │
        ├── GitHub Pages
        │
        ▼
  AI Summary Input Model
        │
        ▼
   AI Summary Generation
```

The project separates data acquisition and transformation from AI summarization.

Python transforms source data into purpose-specific, semantically structured input models. AI is responsible for summarization and expression based on those structured inputs.

---

## Public Data

The project publishes structured JSON data under `docs/data`.

| File                 | Description                                                 |
| -------------------- | ----------------------------------------------------------- |
| `app.json`           | Application metadata                                        |
| `statistics.json`    | Dashboard statistics                                        |
| `laws.json`          | Public Law model for GitHub Pages and external applications |
| `law_summaries.json` | AI-generated law amendment summaries                        |

The Event model is the canonical internal representation.

The Law model is generated from Event data and is intended for GitHub Pages, WordPress, and future external integrations.

---

## Workflow

GitHub Actions runs every day and performs:

1. Download e-Gov Law Updates
2. Extract ZIP archive
3. Parse CSV
4. Build canonical Event data
5. Generate public Law data
6. Generate AI summaries when required
7. Generate statistics
8. Publish GitHub Pages
9. Send email notifications

---

## AI Summaries

The project provides AI-generated summaries of law amendments.

AI summaries are generated from purpose-specific structured input models rather than raw source data.

The input models are prepared by Python and may include:

* Law and amendment information
* Enforcement dates
* Article-level changes
* Supplementary table changes

AI is responsible for summarizing and expressing the information provided in these models. Detailed amendment text remains available through e-Gov's law comparison pages.

---

## Email Notifications

Notification emails are sent only when law updates are available.

Each email includes:

* Update date
* Number of updates
* Updated laws
* GitHub Pages link

---

## Technologies

* Python 3.13
* GitHub Actions
* GitHub Pages
* HTML
* CSS
* JavaScript
* SMTP
* Gemini API

---

## Roadmap

- e-Gov Data Portal support
- Cabinet Legislation Bureau integration
- Additional official information sources
- Richer law metadata
- Improved Law UI
- Search and filtering

---

## License

MIT License
