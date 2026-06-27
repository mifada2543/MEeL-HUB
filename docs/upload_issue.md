### masalah upload advanced
## solusi
1.  > Restart server
```bash
xrestart
```
2. > masuk ke akun admin -> dashboard admin -> scroll -> matikan queue yang berjalan

## alternatif
```sql
DELETE FROM `upload_queue` 
WHERE `status` = 'processing';
```