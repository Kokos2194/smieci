# Instalacja na NAS Synology (DS223j)

Aplikacja działa na NAS-ie jako mała strona z serwerem PHP i bazą SQLite (bez Dockera). Wszyscy pracują na jednej wspólnej bazie: każdy ma własne konto, a przy usterce widać, kto ją wpisał i kto poprawił. Bez zasięgu (garaż, piwnica) wpisujesz normalnie, a zmiany wysyłają się same, gdy telefon znowu połączy się z NAS-em.

Dostęp: w sieci firmowej albo z budowy przez Tailscale. **Nie otwieraj dla tej aplikacji portów na routerze.**

Potrzebne: DSM 7.2, konto administratora DSM, około 45 minut. Nazwy menu w DSM mogą się trochę różnić od opisanych. Jeśli coś wygląda inaczej, zrób zrzut ekranu i zapytaj.

---

## 1. Pakiety

DSM → **Centrum pakietów**. Zainstaluj:

- **Web Station**
- **PHP 8.2** (albo najnowsze PHP 8.x na liście)
- **Apache HTTP Server 2.4**
- **Tailscale**
- **Hyper Backup**

## 2. Ustawienia PHP

**Web Station** → **Ustawienia języka skryptowego** (Script Language Settings) → zakładka **PHP** → zaznacz profil PHP 8.2 → **Edytuj**:

- **Rozszerzenia:** zaznacz `pdo_sqlite`. Jeśli są na liście, zaznacz też `sqlite3` i `mbstring`.
- **Core:** `upload_max_filesize` = `16M`, `post_max_size` = `20M`.
- Zapisz.

## 3. Folder `web` obsługiwany przez PHP i Apache

**Web Station** → **Portal usług WWW** (Web Service Portal) → wiersz **Serwer domyślny** (Default server) → **Edytuj**. Ustaw serwer HTTP **Apache 2.4** i profil **PHP 8.2** z kroku 2, potem zapisz.

Apache jest potrzebny, bo to on przestrzega blokady folderu z danymi (plik `.htaccess`).

## 4. Wgranie plików

1. Na GitHubie otwórz repozytorium `smieci` → zielony przycisk **Code** → **Download ZIP**. Rozpakuj plik na komputerze.
2. DSM → **File Station** → folder współdzielony **web** → **Prześlij** → wgraj cały folder **`odbiory`** razem z podfolderem `vendor`.
3. Kliknij folder `odbiory` prawym przyciskiem → **Właściwości** → **Uprawnienia** → **Utwórz**:
   - użytkownik lub grupa: grupa **`http`**,
   - uprawnienia: **Odczyt** i **Zapis**,
   - zaznacz „Zastosuj do tego folderu, podfolderów i plików”.
   - Zatwierdź **OK**.

## 5. Pierwsze uruchomienie (w sieci firmowej)

Na komputerze w sieci firmowej otwórz `http://ADRES-IP-NAS/odbiory/` (adres IP widać w DSM → Panel sterowania → Sieć).

Pojawi się ekran **Pierwsze uruchomienie**. Utwórz konto administratora (imię i nazwisko, login, hasło co najmniej 8 znaków).

Jeśli zamiast tego pojawi się komunikat o błędzie, mówi on, co poprawić, np. uprawnienia z kroku 4 albo `pdo_sqlite` z kroku 2.

W sieci firmowej przez `http://` aplikacja działa w pełni. Tylko praca bez zasięgu i ikona na telefonie wymagają adresu `https://`, który daje krok 6.

## 6. Dostęp z budowy: Tailscale (https)

1. DSM → otwórz pakiet **Tailscale** → zaloguj się (najlepiej kontem firmowym).
2. W przeglądarce otwórz panel Tailscale (login.tailscale.com) → **DNS** → włącz **MagicDNS** i **HTTPS Certificates**.
3. DSM → **Panel sterowania** → **Harmonogram zadań** → **Utwórz** → **Zadanie wyzwalane** → **Skrypt zdefiniowany przez użytkownika**:
   - Ogólne: użytkownik **root**, zdarzenie **Uruchomienie** (Boot-up),
   - Ustawienia zadania, skrypt:
     ```
     /var/packages/Tailscale/target/bin/tailscale serve --bg http://127.0.0.1:80
     ```
   - Zatwierdź **OK**, potem zaznacz zadanie i kliknij **Uruchom**.
4. Adres aplikacji: `https://NAZWA-NAS.TWOJA-SIEC.ts.net/odbiory/`. Pełną nazwę NAS-a widać w panelu Tailscale (Machines).
5. Telefony: zainstaluj aplikację **Tailscale** (App Store / Google Play) i zaloguj się do tej samej sieci. Nowe osoby zapraszasz w panelu Tailscale (**Users → Invite**). Potem otwórz adres z punktu 4 i dodaj aplikację do ekranu głównego:
   - iPhone: Safari → Udostępnij → „Do ekranu początkowego”,
   - Android: Chrome → menu ⋮ → „Zainstaluj aplikację”.

`tailscale serve` udostępnia aplikację tylko urządzeniom w Twojej sieci Tailscale, nie całemu internetowi.

## 7. Konta pracowników

W aplikacji: **Ustawienia** (zębatka) → **Użytkownicy** → **Dodaj**. Wpisz imię i nazwisko, login i hasło (przycisk „Losuj” podpowiada hasło), wybierz uprawnienia:

- **Kontroler:** wpisuje i odhacza usterki, robi zestawienia.
- **Administrator:** dodatkowo zarządza kontami, widzi historię zmian i może wczytać przykład.

Konto osoby, która odchodzi, wyłączasz polem „Konto aktywne”. Jej wpisy zostają.

## 8. Kopie zapasowe

1. **Menedżer pamięci:** sprawdź, czy pula pamięci ma dwa dyski w **RAID 1** albo **SHR** (stan „W dobrym stanie”). RAID chroni przed awarią jednego dysku, ale **nie jest kopią zapasową**.
2. **Hyper Backup** → **Utwórz** → **Zadanie kopii zapasowej danych**:
   - miejsce docelowe: dysk USB („Folder lokalny i USB”) albo chmura (np. Synology C2),
   - folder: `web/odbiory`,
   - harmonogram: codziennie, np. o 22:00,
   - rotacja kopii: włączona (Smart Recycle).
3. Aplikacja sama robi codziennie spójną kopię bazy (folder `data-…/backup`, ostatnie 14 dni), więc Hyper Backup zawsze zabiera pełną bazę.
4. Raz na kilka miesięcy sprawdź w Hyper Backup, czy da się przywrócić plik (Przywróć → podgląd).

---

## Aktualizacja aplikacji

Pobierz nowy ZIP z GitHuba i w File Station nadpisz w folderze `odbiory`: `index.html`, `api.php`, `sw.js`, `manifest.webmanifest`, ikony i folder `vendor`.

**Nigdy nie usuwaj pliku `config.local.php` ani folderu `data-…`.** To są Wasze dane.

## Ograniczenia DS223j

- Kilka do kilkunastu osób pracujących jednocześnie: bez problemu. Kilkadziesiąt naraz: za słaby procesor i pamięć.
- Zdjęcie po zmniejszeniu w telefonie ma ok. 200–400 KB, więc 1000 zdjęć zajmuje ok. 300 MB.
