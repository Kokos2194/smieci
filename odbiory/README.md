# Protokół usterek

Prosta aplikacja do odbiorów budynku wielorodzinnego (działa na telefonie, także bez internetu).

- **Budowa → (klatki) → kondygnacje → mieszkania.** Budynek jest pokazany jako przekrój: na górze dach, niżej piętra, pod parterem linia terenu. Każda kondygnacja to jeden rząd lokali; gdy się nie mieści, przekrój przesuwa się w bok. Klatki są opcjonalne: stoją obok siebie, rozdzielone grubą ścianą, i można oglądać jedną naraz. Generator od razu tworzy piętra, klatki i mieszkania z numeracją (M1, M2… albo M1.1, M1.2…).
- **Garaże i komórki lokatorskie.** W generatorze: jedna hala garażowa albo garaże indywidualne (G1, G2…), komórki na −1 (ile w każdej klatce) i opcjonalnie na piętrach (K1, K2…). Na przekroju garaże są jednym polem na poziomie −1, a komórki wąskim znacznikiem „K ×2” na końcu piętra; po dotknięciu otwiera się lista numerów.
- **Usterki w mieszkaniu.** Rodzaj (okna, drzwi, elektryka, hydraulika, ogrzewanie, wentylacja, tynki, malowanie, posadzki, płytki, balkon, inne) i typowe usterki wybiera się z listy. Można też wpisać własny opis, pomieszczenie i uwagi.
- **Zdjęcia.** Zdjęcie robi się aparatem albo wybiera z galerii. Aplikacja je zmniejsza i nanosi na nie datę i godzinę.
- **Odhaczanie.** Duże pole „poprawione” przy każdej usterce, z datą poprawy.
- **Zestawienia.** Tabela rodzaj × kondygnacja i filtr wg rodzaju, statusu, klatki i kondygnacji. Eksport do PDF (protokół z miejscem na podpisy, opcjonalnie ze zdjęciami), do Excela (CSV) albo jako tekst do wklejenia w SMS lub WhatsApp.
- **Kopia zapasowa.** Plik .json ze wszystkimi danymi i zdjęciami.

Aplikacja startuje pusta. Przykładową budowę można wczytać przyciskiem na ekranie startowym albo w Ustawieniach.

**Dwa tryby pracy (ta sama aplikacja):**
- **Na serwerze NAS (Synology):** wspólna baza dla całego zespołu (PHP + SQLite, plik `api.php`). Każdy ma własne konto, przy usterce widać, kto ją wpisał i kto poprawił, administrator zarządza kontami i widzi historię zmian. Bez zasięgu zmiany czekają w telefonie i wysyłają się same. Instalacja: [INSTALACJA.md](INSTALACJA.md).
- **Bez serwera** (np. GitHub Pages): dane zapisywane w przeglądarce na urządzeniu (IndexedDB). Aplikację można dodać do ekranu głównego telefonu.

**Link do budowy (np. w Nozbe).** W menu ⋯ budowy jest link, który otwiera aplikację od razu na tej budowie (adres z dopiskiem `#b-nazwa-budowy`). Można go wkleić w zadaniu lub projekcie w Nozbe.

Pliki: `index.html` (cała aplikacja), `api.php` (serwer na NAS), `sw.js` (działanie offline), `vendor/` (pdfmake 0.2.10, licencja MIT, do tworzenia PDF).
