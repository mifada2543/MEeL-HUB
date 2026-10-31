# JMdict SQLite (skema v2)

Kamus ini dipakai `modules/core/japanese.php` untuk memberi gloss bahasa
Inggris pada metadata Jepang. MeCab hanya menyediakan segmentasi, bacaan, dan
part of speech; arti kata berasal dari JMdict.

## Sumber dan lisensi

Data berasal dari **JMdict**, milik Electronic Dictionary Research and
Development Group (EDRDG), dan didistribusikan di bawah
[Creative Commons Attribution-ShareAlike 4.0 International (CC BY-SA 4.0)](https://www.edrdg.org/edrdg/licence.html).

- Proyek: https://www.edrdg.org/wiki/index.php/JMdict-EDICT_Dictionary_Project
- Lisensi: https://www.edrdg.org/edrdg/licence.html

Jika kamus ini didistribusikan ulang (termasuk di dalam paket MEeL), atribusi
di atas dan lisensi CC BY-SA 4.0 harus tetap disertakan.

## Skema

```sql
CREATE TABLE entries (
    id INTEGER PRIMARY KEY,
    glosses TEXT NOT NULL
);

CREATE TABLE keys (
    reading  TEXT NOT NULL,   -- bentuk kanji atau kana
    pos      INTEGER NOT NULL, -- rowid sumber, menjaga urutan LIMIT 1
    entry_id INTEGER NOT NULL,
    PRIMARY KEY (reading, pos)
) WITHOUT ROWID;

PRAGMA user_version = 2;
```

Skema v1 menyimpan gloss yang sama berulang kali untuk setiap bentuk kata
(496.262 baris, hanya 196.327 gloss unik). Skema v2 memisahkan gloss dari kunci
pencarian sehingga ukuran turun dari 41 MB menjadi sekitar 21 MB.

## Query runtime

```sql
SELECT e.glosses
FROM keys k
JOIN entries e ON e.id = k.entry_id
WHERE k.reading = :w
ORDER BY k.pos
LIMIT 1
```

`ORDER BY k.pos` mempertahankan perilaku `LIMIT 1` skema lama, termasuk untuk
reading yang punya banyak homofon (mis. `こう`).

## Membangun ulang

Dari database skema v1:

```bash
php scripts/build-jmdict-sqlite.php \
  --source=/path/ke/jmdict-v1.sqlite3 \
  --out=/tmp/jmdict-v2.sqlite3 --force

mv /tmp/jmdict-v2.sqlite3 assets/dict/jmdict.sqlite3
```

Skrip memverifikasi jumlah baris, `PRAGMA integrity_check`, dan kesamaan hasil
lookup pada 500 reading acak sebelum menulis output.
