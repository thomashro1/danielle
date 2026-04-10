# DANIELLE Customer App

Native Flutter-App fuer den mobilen Kundenbereich von DANIELLE.

## API

Standard-Endpunkt:

`https://himi10.de/danielle/customer_api.php`

Bei Bedarf kann beim Build ein anderer Endpunkt uebergeben werden:

```powershell
flutter run --dart-define=API_BASE_URL=https://example.org/customer_api.php
```

## Lokale Entwicklung

```powershell
flutter pub get
flutter analyze
flutter test
flutter run
```

## Release-Build

```powershell
$env:JAVA_HOME='C:\Program Files\Microsoft\jdk-17.0.18.8-hotspot'
flutter build apk --release --dart-define=API_BASE_URL=https://himi10.de/danielle/customer_api.php
```

Die Release-APK liegt anschliessend hier:

`build/app/outputs/flutter-apk/app-release.apk`

## Funktionsumfang

- Kunden-Login mit tokenbasierter Session
- Uebersicht mit Status- und Dokumentenzahlen
- Problem-/Anliegen-Meldung ans Backoffice
- Statushistorie und neue Statusmeldungen
- Dokumentenliste mit Download und Upload
- Pflege der eigenen Kontaktdaten
