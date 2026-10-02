# Circuleather

Dit web applicatie is voor het beheren van de leervoorraad en klantbestellingen.
Na het inloggen kunnen gebruikers leerpartijen of individuele stukken bekijken,
zoeken en toevoegen. Om een ​​bestelling aan te maken, voeren ze klantgegevens in,
selecteren ze beschikbare materialen en geven ze de hoeveelheden op. De website
berekent het totaalbedrag en werkt de voorraad bij zodra de bestelling wordt
opgeslagen. Partij- en bestelformulieren kunnen worden ingevuld via Excel- of
CSV-bestanden; gebruikers controleren de geïmporteerde gegevens voordat ze
deze opslaan. Bestaande gegevens kunnen worden bewerkt en meldingen wijzen op
een lage voorraad of wijzigingen. Detailpagina's kunnen als PNG-afbeelding worden
geëxporteerd. Beheerders beheren de gebruikersaccounts en elke gebruiker kan
kiezen tussen Nederlands en Engels.

De website gebruikt **HTML en CSS** voor de opbouw en vormgeving, **JavaScript**
voor interacties en **PHP** voor het verwerken van gegevens. **SQL en MariaDB**
verzorgen de gegevensopslag. Docker start de webserver en database samen;
phpMyAdmin is optioneel voor databasebeheer. SheetJS verzorgt Excel-import en
html2canvas de PNG-export.

## Installatie voor Circuleather

> **Hulp nodig bij een stap?** Je kunt een AI-assistent vragen om de instructie
> eenvoudiger uit te leggen of mee te kijken naar een foutmelding. Geef aan welke
> stap je uitvoert, welk besturingssysteem je gebruikt en wat er op het scherm
> verschijnt. Deel geen wachtwoorden, de inhoud van jullie `.env` of klantgegevens.
> Laat bij twijfel de IT-beheerder meekijken voordat je een voorgestelde opdracht uitvoert.

### Wat wordt er geïnstalleerd?

De website praat rechtstreeks met een MariaDB-database. **phpMyAdmin is een
optioneel beheerscherm voor die database**, geen onderdeel waar de website van
afhankelijk is. Docker start de PHP-webserver en de database samen. De gegevens
staan op jullie eigen computer of server; mijn laptop hoeft niet aan te staan.
Medewerkers openen de website in hun browser en hoeven geen code te bekijken.

Deze installatie begint met een lege database: mijn testaccounts, klanten,
bestellingen en voorraad worden niet meegenomen.

### 1. Kies de computer die de website draait

Gebruik een computer of server die tijdens het gebruik aan blijft staan. Alleen
daar is Docker nodig; andere medewerkers hebben uitsluitend een browser nodig.
Installeer en start [Docker Desktop](https://docs.docker.com/compose/install/)
op Windows/macOS, of Docker Engine met Compose op een Linux-server.

Open PowerShell of een terminal en controleer:

```sh
docker --version
docker compose version
```

Voor de installatie en sommige functies is internet nodig. Normaal hoeft hiervoor
niets te worden ingesteld. Werken Excel-import of PNG-export niet, vraag dan de
IT-beheerder om de internetverbindingen te controleren. De installatie downloadt
Docker-images; de website laadt lettertypen en bestanden voor Excel-import en
PNG-export van externe websites.

### 2. Download de website

Open [de GitHub-repository](https://github.com/inosuke1905/Fullstack-Circuleather),
kies **Code → Download ZIP** en pak het bestand uit. Bewaar de uitgepakte map op
de computer/server die de website gaat draaien.

Open een terminal in de hoofdmap: dit is de map met `README.md`, `compose.yaml`
en `Dockerfile`. Voer de volgende opdrachten steeds vanuit die map uit.

### 3. Stel de databasewachtwoorden in

Maak een kopie van `.env.example` en noem deze `.env`. In PowerShell:

```powershell
Copy-Item .env.example .env
```

Op Linux/macOS kan dit met `cp .env.example .env`. Open `.env` in een teksteditor
en vul twee verschillende, lange willekeurige wachtwoorden in:

```dotenv
DB_PASSWORD='jullie-eigen-databasewachtwoord'
DB_ROOT_PASSWORD='jullie-eigen-beheerderswachtwoord'
APP_PORT=8080
PHPMYADMIN_PORT=8081
```

Vervang de voorbeeldwachtwoorden. Bewaar `.env` privé; dit bestand wordt niet in
Git opgenomen. Het databasewachtwoord is niet het wachtwoord waarmee medewerkers
op de website inloggen. De applicatiegebruiker van de database heet `circuleather`.
Dit is het enige configuratiebestand dat jullie voor deze installatie aanpassen;
de PHP-, JavaScript- en SQL-bestanden hoeven niet te worden bewerkt.

### 4. Start de website

Voer dit uit op de computer waarop jullie de website hebben geïnstalleerd.

1. Zorg dat Docker draait. Gebruik je Docker Desktop? Open het programma en
   wacht totdat het aangeeft dat Docker klaar is.
2. Open PowerShell of een terminal in de uitgepakte projectmap. Je moet hier
   `README.md`, `compose.yaml` en jullie ingevulde `.env` kunnen vinden.
3. Kopieer deze opdracht naar de terminal en druk op **Enter**:

   ```sh
   docker compose up -d --build
   ```

4. Wacht tot de opdracht klaar is en je weer een nieuwe opdracht kunt typen.
   De eerste keer kan dit enkele minuten duren. Docker downloadt de benodigde
   onderdelen en maakt de database automatisch aan. Je hoeft geen SQL-bestanden
   te openen of iets in phpMyAdmin te importeren.
5. Controleer of alles draait met:

   ```sh
   docker compose ps
   ```

   Bij **app** hoort in de kolom **STATUS** `Up` te staan. Bij **mysql** hoort
   `Up` met `(healthy)` te staan. Staat er nog `(health: starting)`? Wacht even
   en voer deze controle nogmaals uit. Ontbreekt een onderdeel of staat er
   `Exited`? Kijk onder **Als iets niet werkt** voordat je verdergaat.

Je kunt nu het eerste inlogaccount aanmaken. De database begint leeg; voer de
andere bestanden in de map `Circuleather/database/` niet zelf uit.

### 5. Maak een account en log in

Dit doe je één keer. Dit eerste account is de beheerder en kan later accounts
voor medewerkers aanmaken.

1. Gebruik dezelfde terminal als in stap 4. Kopieer deze opdracht en druk op
   **Enter**:

   ```sh
   docker compose exec app php /opt/circuleather/create-admin.php
   ```

2. De terminal vraagt achtereenvolgens om vier gegevens. Typ je antwoord en
   druk steeds op **Enter**:

   | Vraag in de terminal | Wat vul je in? |
   | --- | --- |
   | `Administrator name:` | Je naam |
   | `Administrator email:` | Het e-mailadres waarmee je wilt inloggen |
   | `Password (10 to 72 bytes):` | Een nieuw wachtwoord voor de website |
   | `Confirm password:` | Hetzelfde wachtwoord nog een keer |

   Gebruik voor een eenvoudige keuze 10 tot 72 letters, cijfers en leestekens;
   kies een lang, uniek wachtwoord. Tijdens het typen van het wachtwoord zie je
   geen tekens verschijnen. Dat is normaal. Gebruik hiervoor een ander
   wachtwoord dan de databasewachtwoorden uit stap 3.
3. Zie je **Administrator created**? Dan is het account aangemaakt. Zie je een
   foutmelding? Volg die melding en voer de opdracht opnieuw uit. Meldt het
   programma dat er al een beheerder bestaat, gebruik dan dat bestaande account.
4. Open op deze computer je browser en vul dit adres in de adresbalk in:

   ```text
   http://localhost:8080/circuleather/
   ```

   Heb je in stap 3 een andere `APP_PORT` gekozen? Vervang dan `8080` door dat
   poortnummer.
5. Log in met het e-mailadres en het websitewachtwoord die je zojuist hebt
   ingevuld. Je komt nu op de voorraadpagina. Deze is bij een nieuwe installatie
   nog leeg.
6. Open **Gebruikers** als je ook accounts voor medewerkers wilt aanmaken.

### 6. Laat medewerkers de website openen

De computer uit stap 4 blijft de website draaien. Medewerkers hoeven op hun
eigen computer niets te installeren. Laat de IT-beheerder helpen met het
bereikbaar maken op jullie bedrijfsnetwerk.

1. Verbind de computer waarop de website draait en de computers van medewerkers
   met hetzelfde bedrijfsnetwerk.
2. Zoek op de computer waarop de website draait het netwerkadres. Open op
   Windows PowerShell, typ de volgende opdracht en druk op **Enter**:

   ```powershell
   ipconfig
   ```

   Zoek bij de verbinding die je gebruikt (**Wi-Fi** of **Ethernet**) naar
   **IPv4-adres / IPv4 Address**. Noteer dit adres, bijvoorbeeld `192.168.1.50`.
   Gebruik niet het adres van een Docker- of andere virtuele verbinding.
   Op Linux/macOS kun je dit adres in de netwerkinstellingen vinden.
3. Open op de computer van een medewerker een browser. Vul het volgende adres
   in en vervang `192.168.1.50` door het adres dat je zojuist hebt genoteerd:

   ```text
   http://192.168.1.50:8080/circuleather/
   ```

   Gebruik ook hier jullie eigen poortnummer als `APP_PORT` niet `8080` is.
   `localhost` werkt alleen op de computer waarop de website zelf draait.
4. Verschijnt het inlogscherm? Dan is de verbinding gelukt. De medewerker kan
   inloggen met het eigen account dat de beheerder in stap 5 heeft aangemaakt.
   Verschijnt het niet? Vraag de IT-beheerder om de netwerkverbinding en firewall
   te controleren en toegang tot de gekozen websitepoort toe te staan vanaf het
   bedrijfsnetwerk. De database hoeft niet rechtstreeks bereikbaar te worden.
5. Laat de IT-beheerder vóór dagelijks gebruik een vast netwerkadres en een
   beveiligd **HTTPS-adres** instellen. Daarmee blijft de website op hetzelfde
   adres bereikbaar en worden wachtwoorden en klantgegevens versleuteld
   verstuurd. Deel daarna dat definitieve adres met medewerkers. De HTTP-adressen
   hierboven zijn bedoeld om de installatie te controleren.
6. Houd de computer en Docker aan tijdens het gebruik. Laat de computer niet in
   slaapstand gaan, anders kunnen medewerkers de website niet openen.

Deze installatie is bedoeld voor het bedrijfsnetwerk. Er hoeft geen toegang
vanaf het openbare internet te worden ingesteld.

### Optioneel: de database bekijken met phpMyAdmin

```sh
docker compose --profile admin up -d phpmyadmin
```

Open **op de server zelf** `http://localhost:8081`. Log in met gebruiker
`circuleather` en `DB_PASSWORD` uit `.env`. Kies de database `circuleather` om
tabellen te bekijken of een SQL-export te maken. Voor databasebeheer als root
gebruik je `root` met `DB_ROOT_PASSWORD`.

phpMyAdmin is uitsluitend bereikbaar op de server zelf. Het kan worden gestopt
zonder de website of database te stoppen:

```sh
docker compose stop phpmyadmin
```

### Gegevens bewaren, stoppen en opnieuw starten

Docker bewaart de database en foto's in afzonderlijke volumes. Een normale
containerherstart of nieuwe applicatiebuild wist deze gegevens niet.

```sh
docker compose stop
docker compose start app mysql
```

Gebruik **geen `docker compose down -v`** voor een bestaande installatie: `-v`
verwijdert de volumes met jullie gegevens. Bewaar ook de gebruikte `.env`.
Een wachtwoord in `.env` aanpassen wijzigt niet automatisch een bestaande
databasegebruiker; laat wachtwoordwijzigingen door de beheerder uitvoeren.

Maak regelmatig een SQL-export via phpMyAdmin en bewaar ook de foto's. De foto's
kunnen naar een lokale backupmap worden gekopieerd:

```sh
mkdir backups
docker compose cp app:/var/www/html/circuleather/uploads backups/uploads
```

Bewaar backups op een tweede locatie. Een GitHub-download of een Docker-image
bevat de broncode, niet jullie actuele database of foto's.

### Als iets niet werkt

- **Docker draait niet:** start Docker Desktop of de Docker-service en probeer opnieuw.
- **Poort is al bezet:** kies een vrije `APP_PORT` in `.env`, voer
  `docker compose up -d` uit en gebruik het nieuwe poortnummer in de URL.
- **Website start niet:** controleer `docker compose ps` en
  `docker compose logs --tail=100 app mysql`.
- **Website werkt op de server maar niet bij collega's:** controleer het IP-adres,
  de firewall, het bedrijfsnetwerk en eventuele gastnetwerkisolatie.
- **Geen tabellen na de eerste start:** bekijk de database-log. Alleen een leeg
  databasevolume voert de installatie-SQL uit; gebruik voor een bestaande database
  een gecontroleerde import of herstelactie en wis de volumes niet zomaar.
- **Geen beheerdersaccount:** voer de opdracht uit stap 5 uit. Is er al een
  beheerder, gebruik dan diens accountbeheer in de website.

Officiële achtergrondinformatie: [Docker Compose](https://docs.docker.com/compose/install/),
[MariaDB-initialisatie](https://mariadb.com/docs/server/server-management/automated-mariadb-deployment-and-administration/docker-and-mariadb/mariadb-server-docker-official-image-environment-variables)
en [phpMyAdmin in Docker](https://docs.phpmyadmin.net/en/latest/setup.html#installing-using-docker).

## Technische documentatie

### How a request works

1. `Circuleather/index.php` starts the session and refreshes the account's identity.
2. It validates the requested page, permissions, record ID and inventory type.
3. For a submitted form, it runs the corresponding `actions/` handler before
   printing HTML. Successful handlers redirect so refreshing does not repeat a save.
4. It includes the page template inside the shared application layout.
5. Page-specific JavaScript adds previews and interactions. The PHP actions still
   validate submitted values and calculate the saved totals.

Each source file has a purpose comment, with additional comments around its
transactions, validation rules and less obvious behavior.

## Where to find the code

| Location | Responsibility |
| --- | --- |
| `Circuleather/index.php` | Sessions, routing, settings, shared layout and asset loading |
| `Circuleather/db.php` | One shared MySQLi connection per request |
| `Circuleather/pages/` | Inventory, orders, account screens and detail-page export markup |
| `Circuleather/actions/` | Form submissions and authenticated JSON endpoints |
| `Circuleather/lib/order-editing.php` | Order revisions, stock reservations, editing and deletion |
| `Circuleather/lib/notifications.php` | Localized alerts and before/after change records |
| `Circuleather/excel-import.js` | Spreadsheet parsing, column matching and filling a selected row |
| `Circuleather/order-form.js` | New-order rows and live price/stock estimates |
| `Circuleather/order-detail.js` | Existing-order rows, prices and status controls |
| `Circuleather/notifications.js` | Notification polling, dropdown and toasts |
| `Circuleather/account-menu.js` | Closing the account menu after an outside click |
| `Circuleather/share-links.js` | Capturing the prepared export card as a PNG |
| `Circuleather/share.php` | Read-only public views for valid share tokens |
| `Circuleather/style.css` | Layout, components, responsive rules and theme overrides |
| `Circuleather/database/` | Explicit database setup and historical upgrade scripts |
| `Circuleather/tests/` | Isolated database integration tests and browser import checks |
| `Circuleather/uploads/batches/` | Stored material photos; these are binary assets, not source files |

## Important data rules

- Kilogram inventory lives in `batches`; individual items live in `individual_pieces`.
  Their numeric IDs may overlap, so order editing uses keys such as `batch:17`
  and `piece:4` to identify both the table and the record.
- Creating an order reserves stock and copies material details into its lines.
  These snapshots preserve historical descriptions when inventory is later edited.
- Editing reconciles the old and new stock reservations in one transaction.
  Revision hashes reject a form when another save has changed the order.
- Cancelling returns the reservation. Deleting an open or processing order returns
  stock; deleting a shipped, delivered or already cancelled order does not.
- Customers are shared by email. Deleting an order retains its customer record.
- Spreadsheet imports fill one selected row. They do not create inventory or save
  an order until the normal form is submitted.

## Runtime and database configuration

The application uses PHP with MySQLi, mbstring and fileinfo, plus a database with
the application's tables. Connection settings come from `DB_HOST`, `DB_NAME`,
`DB_USER` and `DB_PASSWORD`; `db.php` contains the existing local defaults.

The delivery Dockerfile copies the application to `/var/www/html/circuleather`.
Use the lowercase `/circuleather/` URL. The older development Docker stack on the
author's laptop is separate from this standalone client setup.

`install.sql` is the complete, empty-database schema used by the client setup.
The other SQL files are historical upgrades, not files executed by each page
request. In particular, `individual_pieces.sql` is a one-time historical migration
and must not be rerun against a database that already has separate piece storage.

`style.css` contains base rules followed by component and final brand overrides.
Its section comments explain the boundaries. Moving or merging those sections
without checking the cascade can change the current appearance.

## Verification

The PHP integration tests deliberately require separate database names. Provision
each database with the application's schema and suitable test-user permissions;
the tests write and delete fixtures inside those databases.

```sh
DB_NAME=circuleather_order_edit_test php Circuleather/tests/order-editing.php
DB_NAME=circuleather_storage_test php Circuleather/tests/piece-storage.php create-batch
DB_NAME=circuleather_storage_test php Circuleather/tests/piece-storage.php create-piece
DB_NAME=circuleather_storage_test php Circuleather/tests/piece-storage.php order-batch
DB_NAME=circuleather_storage_test php Circuleather/tests/piece-storage.php order-piece
DB_NAME=circuleather_storage_test php Circuleather/tests/piece-storage.php order-multi-batch
DB_NAME=circuleather_storage_test php Circuleather/tests/piece-storage.php order-multi-piece
DB_NAME=circuleather_storage_test php Circuleather/tests/piece-storage.php render
```

Run the creation modes before the order modes, using an empty test database.
The storage test also supports `create-delete-piece`, `edit-piece`,
`reject-fraction`, `reject-delete-piece` and `delete-piece` for focused checks.

The browser test needs Node.js, Chrome and a local copy of the same
`xlsx.full.min.js` version loaded by `index.php`:

```sh
node Circuleather/tests/excel-import.cjs /path/to/xlsx.full.min.js
```

Run that command from the repository root. Set `CHROME_PATH` if Chrome is installed
outside the test's default Windows location. The test uses temporary profiles and
form fixtures; it does not log into the website or submit data to the app database.
