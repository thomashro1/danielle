# DANIELLE CRM

Das Projekt besteht aus einem PHP-Backoffice, einem mobilen Kundenbereich und einer nativen Flutter-Android-App fuer die Customer-Funktionen.

## Produktiv erreichbar

- Web-Anwendung: `https://himi10.de/danielle/`
- Customer-API: `https://himi10.de/danielle/customer_api.php`
- Android-App-Quellcode: `mobile/danielle_customer_app/`

## Lokale Entwicklung

1. In PowerShell im Projektordner ausfuehren:

```powershell
.\scripts\start-local.ps1
```

2. Danach im Browser oeffnen:

`http://127.0.0.1:8080/`

## Original-Dump lokal einspielen

```powershell
.\scripts\import-original-dump.ps1
```

Dabei wird die aktuell verwendete lokale DB zuerst unter `.local\backups\` gesichert und die App anschliessend auf den importierten Datenbestand umgestellt.

## Customer-API

Die API ist fuer die native App und weitere mobile Clients vorgesehen. Sie arbeitet tokenbasiert ueber Bearer-Token.

Wichtige Endpunkte:

- `GET customer_api.php?action=ping`
- `POST customer_api.php?action=login`
- `POST customer_api.php?action=logout`
- `GET customer_api.php?action=me`
- `POST customer_api.php?action=me_update`
- `GET customer_api.php?action=status_types`
- `GET customer_api.php?action=status_list`
- `POST customer_api.php?action=status_add`
- `GET customer_api.php?action=documents_list`
- `GET customer_api.php?action=document_download&id=...`
- `POST customer_api.php?action=document_upload`
- `POST customer_api.php?action=problem_report`
- `GET customer_api.php?action=home_summary`

Schema-Erweiterung fuer die API:

- `database/03-customer-api.sql`

## Android-App

Die native Android-App nutzt standardmaessig die produktive API unter `https://himi10.de/danielle/customer_api.php`.

Build:

```powershell
cd .\mobile\danielle_customer_app
$env:JAVA_HOME='C:\Program Files\Microsoft\jdk-17.0.18.8-hotspot'
flutter pub get
flutter build apk --release --dart-define=API_BASE_URL=https://himi10.de/danielle/customer_api.php
```

APK-Ausgabe:

- `mobile/danielle_customer_app/build/app/outputs/flutter-apk/app-release.apk`

## Demo-Zugaenge

- Backoffice: `admin` / `admin123`
- Backoffice: `danielle@mail.de` / `12345`
- Kundenportal / App: `krueger@hpm-gmbh.net` / `12345`
- Kundenportal / App: `info@heidi.de` / `12345`

## Wichtige Dateien

- `.env.local`: lokale Laufzeit- und Datenbankwerte
- `app_config.php`: zentrale Konfiguration und gemeinsame Helper
- `careconnect.php`: Backoffice-API inklusive Meldungsradar fuer Live-Hinweise
- `customer_api.php`: mobile Customer-API
- `database/01-schema.sql`: lokales Basisschema
- `database/02-seed.sql`: lokale Demo-Daten
- `database/03-customer-api.sql`: Token-Tabelle fuer die mobile API
- `database/05-backoffice-alert-radar.sql`: Einstellungsspeicher fuer den Backoffice-Meldungsradar
- `c18x5xqr2_mysql_service_one_com.sql`: Original-Dump der bisherigen Demo-Datenbank
- `scripts/bootstrap-local.ps1`: legt DB und User an, importiert Schema und Seed
- `scripts/import-original-dump.ps1`: spielt den mitgelieferten Original-Dump lokal ein und legt vorher ein Backup an
- `scripts/start-local.ps1`: startet DB bei Bedarf und den lokalen PHP-Server
- `scripts/stop-local.ps1`: stoppt nur den lokalen PHP-Server

## Hinweise

- Standardmaessig wird XAMPP unter `C:\xampp` erwartet.
- Standardmaessig nutzt das lokale Setup den XAMPP-Root-User auf `localhost`.
- Die Web-Anwendung schuetzt `.env`, `.sql` und weitere sensible Dateien ueber `.htaccess`.
- Die Mail-Funktion nutzt IMAP. Reale Postfaecher muessen im Backoffice unter Einstellungen gepflegt werden.
- Der Backoffice-Meldungsradar aktualisiert sich minuetlich und kann in den Einstellungen ueber die Altersschwelle fuer neue Wiedervorlagen parametrisiert werden.
