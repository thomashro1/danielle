SET @add_birthdate_column = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'customers'
              AND COLUMN_NAME = 'geburtsdatum'
        ),
        'SELECT 1',
        'ALTER TABLE customers ADD COLUMN geburtsdatum DATE NULL AFTER nachname'
    )
);
PREPARE stmt_add_birthdate_column FROM @add_birthdate_column;
EXECUTE stmt_add_birthdate_column;
DEALLOCATE PREPARE stmt_add_birthdate_column;

INSERT INTO customers (
    anrede,
    vorname,
    nachname,
    geburtsdatum,
    adresse,
    telefon,
    email,
    password,
    kontakt_anrede,
    kontakt_vorname,
    kontakt_nachname,
    kontakt_adresse,
    kontakt_telefon
) VALUES
    ('Frau', 'Anna', 'Bergmann', '1984-03-12', 'Ravensberger Strasse 18, 33602 Bielefeld', '0521 967431', 'anna.bergmann@danielle-test.example', 'test12345', 'Herr', 'Jan', 'Bergmann', 'Ravensberger Strasse 18, 33602 Bielefeld', '0521 967432'),
    ('Herr', 'Lukas', 'Neumann', '1978-07-28', 'Detmolder Strasse 61, 33100 Paderborn', '05251 408217', 'lukas.neumann@danielle-test.example', 'test12345', 'Frau', 'Mira', 'Neumann', 'Detmolder Strasse 61, 33100 Paderborn', '05251 408218'),
    ('Frau', 'Sabine', 'Kruger', '1969-11-04', 'Lange Strasse 22, 32756 Detmold', '05231 221845', 'sabine.kruger@danielle-test.example', 'test12345', 'Herr', 'Tobias', 'Kruger', 'Lange Strasse 22, 32756 Detmold', '05231 221846'),
    ('Herr', 'Matthias', 'Lorenz', '1988-01-19', 'Kampstrasse 15, 44137 Dortmund', '0231 489125', 'matthias.lorenz@danielle-test.example', 'test12345', 'Frau', 'Julia', 'Lorenz', 'Kampstrasse 15, 44137 Dortmund', '0231 489126'),
    ('Frau', 'Heike', 'Schulte', '1975-09-03', 'Meller Strasse 49, 49082 Osnabrueck', '0541 774981', 'heike.schulte@danielle-test.example', 'test12345', 'Herr', 'Ralf', 'Schulte', 'Meller Strasse 49, 49082 Osnabrueck', '0541 774982'),
    ('Herr', 'Stefan', 'Albers', '1990-06-25', 'Bahnhofstrasse 31, 26122 Oldenburg', '0441 563201', 'stefan.albers@danielle-test.example', 'test12345', 'Frau', 'Nina', 'Albers', 'Bahnhofstrasse 31, 26122 Oldenburg', '0441 563202'),
    ('Frau', 'Petra', 'Hoffmann', '1982-02-14', 'Bremer Strasse 73, 28203 Bremen', '0421 338712', 'petra.hoffmann@danielle-test.example', 'test12345', 'Herr', 'Sven', 'Hoffmann', 'Bremer Strasse 73, 28203 Bremen', '0421 338713'),
    ('Herr', 'Carsten', 'Witte', '1971-12-09', 'Hammer Strasse 54, 48153 Muenster', '0251 618742', 'carsten.witte@danielle-test.example', 'test12345', 'Frau', 'Marlies', 'Witte', 'Hammer Strasse 54, 48153 Muenster', '0251 618743'),
    ('Frau', 'Kerstin', 'Baumann', '1993-05-17', 'Lindenweg 11, 32052 Herford', '05221 903116', 'kerstin.baumann@danielle-test.example', 'test12345', 'Herr', 'Dennis', 'Baumann', 'Lindenweg 11, 32052 Herford', '05221 903117'),
    ('Herr', 'Thomas', 'Becker', '1967-08-30', 'Am Stadtpark 9, 32545 Bad Oeynhausen', '05731 447205', 'thomas.becker@danielle-test.example', 'test12345', 'Frau', 'Ines', 'Becker', 'Am Stadtpark 9, 32545 Bad Oeynhausen', '05731 447206'),
    ('Frau', 'Ute', 'Fischer', '1986-04-21', 'Moltkestrasse 42, 32547 Bad Oeynhausen', '05731 819334', 'ute.fischer@danielle-test.example', 'test12345', 'Herr', 'Bernd', 'Fischer', 'Moltkestrasse 42, 32547 Bad Oeynhausen', '05731 819335'),
    ('Herr', 'Jens', 'Pohlmann', '1974-10-07', 'Neustadt 17, 32423 Minden', '0571 260487', 'jens.pohlmann@danielle-test.example', 'test12345', 'Frau', 'Dana', 'Pohlmann', 'Neustadt 17, 32423 Minden', '0571 260488'),
    ('Frau', 'Monika', 'Voss', '1958-01-31', 'Wilhelmstrasse 63, 32427 Minden', '0571 438910', 'monika.voss@danielle-test.example', 'test12345', 'Herr', 'Kai', 'Voss', 'Wilhelmstrasse 63, 32427 Minden', '0571 438911'),
    ('Herr', 'Oliver', 'Riedel', '1981-07-11', 'Bielefelder Strasse 88, 32105 Bad Salzuflen', '05222 716028', 'oliver.riedel@danielle-test.example', 'test12345', 'Frau', 'Petra', 'Riedel', 'Bielefelder Strasse 88, 32105 Bad Salzuflen', '05222 716029'),
    ('Frau', 'Sonja', 'Hartmann', '1995-09-26', 'Lemgoer Strasse 27, 32108 Bad Salzuflen', '05222 403582', 'sonja.hartmann@danielle-test.example', 'test12345', 'Herr', 'Marvin', 'Hartmann', 'Lemgoer Strasse 27, 32108 Bad Salzuflen', '05222 403583'),
    ('Herr', 'Frank', 'Klose', '1963-03-08', 'Paulinenstrasse 29, 32758 Detmold', '05231 667154', 'frank.klose@danielle-test.example', 'test12345', 'Frau', 'Birgit', 'Klose', 'Paulinenstrasse 29, 32758 Detmold', '05231 667155'),
    ('Frau', 'Claudia', 'Winter', '1977-06-18', 'Koenigstrasse 14, 48143 Muenster', '0251 782344', 'claudia.winter@danielle-test.example', 'test12345', 'Herr', 'Michael', 'Winter', 'Koenigstrasse 14, 48143 Muenster', '0251 782345'),
    ('Herr', 'Daniel', 'Reuter', '1989-11-22', 'Nordstrasse 39, 59423 Unna', '02303 559841', 'daniel.reuter@danielle-test.example', 'test12345', 'Frau', 'Leonie', 'Reuter', 'Nordstrasse 39, 59423 Unna', '02303 559842'),
    ('Frau', 'Melanie', 'Kraft', '1983-02-02', 'Friedrichstrasse 26, 58095 Hagen', '02331 617295', 'melanie.kraft@danielle-test.example', 'test12345', 'Herr', 'Patrick', 'Kraft', 'Friedrichstrasse 26, 58095 Hagen', '02331 617296'),
    ('Herr', 'Rene', 'Schramm', '1991-04-14', 'Hohenzollernring 7, 50672 Koeln', '0221 738214', 'rene.schramm@danielle-test.example', 'test12345', 'Frau', 'Sarah', 'Schramm', 'Hohenzollernring 7, 50672 Koeln', '0221 738215'),
    ('Frau', 'Birte', 'Meyer', '1970-08-05', 'Bonner Talweg 58, 53113 Bonn', '0228 614220', 'birte.meyer@danielle-test.example', 'test12345', 'Herr', 'Holger', 'Meyer', 'Bonner Talweg 58, 53113 Bonn', '0228 614221'),
    ('Herr', 'Andreas', 'Schrader', '1965-12-18', 'Kaiserstrasse 33, 60311 Frankfurt am Main', '069 447820', 'andreas.schrader@danielle-test.example', 'test12345', 'Frau', 'Gabriele', 'Schrader', 'Kaiserstrasse 33, 60311 Frankfurt am Main', '069 447821'),
    ('Frau', 'Tanja', 'Lohmann', '1987-05-09', 'Muensterstrasse 91, 48155 Muenster', '0251 947553', 'tanja.lohmann@danielle-test.example', 'test12345', 'Herr', 'Christian', 'Lohmann', 'Muensterstrasse 91, 48155 Muenster', '0251 947554'),
    ('Herr', 'Bjorn', 'Siebert', '1979-01-24', 'Berliner Strasse 44, 33330 Guetersloh', '05241 662917', 'bjorn.siebert@danielle-test.example', 'test12345', 'Frau', 'Maren', 'Siebert', 'Berliner Strasse 44, 33330 Guetersloh', '05241 662918'),
    ('Frau', 'Nadine', 'Engel', '1994-07-06', 'Bahnhofstrasse 12, 33378 Rheda-Wiedenbrueck', '05242 815306', 'nadine.engel@danielle-test.example', 'test12345', 'Herr', 'Florian', 'Engel', 'Bahnhofstrasse 12, 33378 Rheda-Wiedenbrueck', '05242 815307'),
    ('Herr', 'Holger', 'Busch', '1968-10-27', 'Bismarckstrasse 57, 45128 Essen', '0201 571339', 'holger.busch@danielle-test.example', 'test12345', 'Frau', 'Silke', 'Busch', 'Bismarckstrasse 57, 45128 Essen', '0201 571340'),
    ('Frau', 'Eva', 'Kappel', '1976-03-15', 'Weststrasse 20, 59227 Ahlen', '02382 430118', 'eva.kappel@danielle-test.example', 'test12345', 'Herr', 'Norbert', 'Kappel', 'Weststrasse 20, 59227 Ahlen', '02382 430119'),
    ('Herr', 'Marco', 'Gerlach', '1985-09-12', 'Am Ring 16, 59065 Hamm', '02381 284671', 'marco.gerlach@danielle-test.example', 'test12345', 'Frau', 'Saskia', 'Gerlach', 'Am Ring 16, 59065 Hamm', '02381 284672'),
    ('Frau', 'Elke', 'Zimmer', '1961-06-01', 'Marktstrasse 41, 44135 Dortmund', '0231 512084', 'elke.zimmer@danielle-test.example', 'test12345', 'Herr', 'Volker', 'Zimmer', 'Marktstrasse 41, 44135 Dortmund', '0231 512085'),
    ('Herr', 'Patrick', 'Kramer', '1992-11-29', 'Kanalstrasse 24, 44147 Dortmund', '0231 892514', 'patrick.kramer@danielle-test.example', 'test12345', 'Frau', 'Jule', 'Kramer', 'Kanalstrasse 24, 44147 Dortmund', '0231 892515')
ON DUPLICATE KEY UPDATE
    anrede = VALUES(anrede),
    vorname = VALUES(vorname),
    nachname = VALUES(nachname),
    geburtsdatum = VALUES(geburtsdatum),
    adresse = VALUES(adresse),
    telefon = VALUES(telefon),
    password = VALUES(password),
    kontakt_anrede = VALUES(kontakt_anrede),
    kontakt_vorname = VALUES(kontakt_vorname),
    kontakt_nachname = VALUES(kontakt_nachname),
    kontakt_adresse = VALUES(kontakt_adresse),
    kontakt_telefon = VALUES(kontakt_telefon);
