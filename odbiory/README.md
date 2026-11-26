# Protokół usterek

Prosta aplikacja do odbiorów budynku wielorodzinnego (działa na telefonie, także bez internetu).

- **Budowa → (klatki) → kondygnacje → mieszkania.** Budynek jest pokazany jako przekrój: na górze dach, niżej piętra, pod parterem linia terenu. Każda kondygnacja to jeden rząd lokali; gdy się nie mieści, przekrój przesuwa się w bok. Klatki są opcjonalne: stoją obok siebie, rozdzielone grubą ścianą, i można oglądać jedną naraz. Generator od razu tworzy piętra, klatki i mieszkania z numeracją (M1, M2… albo M1.1, M1.2…).
- **Usterki w mieszkaniu.** Rodzaj (okna, drzwi, elektryka, hydraulika, ogrzewanie, wentylacja, tynki, malowanie, posadzki, płytki, balkon, inne) i typowe usterki wybiera się z listy. Można też wpisać własny opis, pomieszczenie i uwagi.
- **Zdjęcia.** Zdjęcie robi się aparatem albo wybiera z galerii. Aplikacja je zmniejsza i nanosi na nie datę i godzinę.
- **Odhaczanie.** Duże pole „poprawione” przy każdej usterce, z datą poprawy.
- **Zestawienia.** Tabela rodzaj × kondygnacja i filtr wg rodzaju, statusu, klatki i kondygnacji. Eksport do PDF (protokół z miejscem na podpisy, opcjonalnie ze zdjęciami), do Excela (CSV) albo jako tekst do wklejenia w SMS lub WhatsApp.
- **Kopia zapasowa.** Plik .json ze wszystkimi danymi i zdjęciami.

Dane są zapisywane w przeglądarce na urządzeniu (IndexedDB). Aplikację można dodać do ekranu głównego telefonu.

Pliki: `index.html` (cała aplikacja), `sw.js` (działanie offline), `vendor/` (pdfmake 0.2.10, licencja MIT, do tworzenia PDF).
