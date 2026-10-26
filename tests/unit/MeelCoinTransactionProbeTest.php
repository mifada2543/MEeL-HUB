<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/support/MysqlFake.php';
class FakeMysqliWithoutInTransaction extends FakeMysqli
{
    /** @var string[] nama savepoint yang masih hidup di sesi ini */
    public array $liveSavepoints = [];
    public bool $inTransaction = false;
    public int $innodbTrxRows = 0;
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->sqls[] = $query;
        if (stripos($query, '@@in_transaction') !== false) {
            throw new mysqli_sql_exception("Unknown system variable 'in_transaction'", 1193);
        }
        if (stripos($query, 'RELEASE SAVEPOINT') === 0) {
            $name = trim(substr($query, strlen('RELEASE SAVEPOINT')), " `");
            if (!in_array($name, $this->liveSavepoints, true)) {
                throw new mysqli_sql_exception('SAVEPOINT ' . $name . ' does not exist', 1305);
            }
            $this->liveSavepoints = array_values(array_diff($this->liveSavepoints, [$name]));
            return true;
        }
        if (stripos($query, 'SAVEPOINT') === 0) {
            $name = trim(substr($query, strlen('SAVEPOINT')), " `");
            if (!$this->inTransaction) {
                return true;
            }
            $this->liveSavepoints[] = $name;
            return true;
        }

        if (stripos($query, 'INNODB_TRX') !== false) {
            return new FakeMysqliResult([['in_tx' => (string) $this->innodbTrxRows]]);
        }

        return parent::query($query, $result_mode);
    }
}
class FakeMysqliRejectingEveryProbe extends FakeMysqli
{
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->sqls[] = $query;
        throw new mysqli_sql_exception('probe ditolak', 1227);
    }
}
class MeelCoinTransactionProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('MeelCoin')) {
            require_once MEEL_ROOT . '/modules/core/MeelCoin.php';
        }
    }
    public function testMariaDbSessionVariableIsUsedWhenAvailable(): void
    {
        $conn = new FakeMysqli();
        $conn->queryResult = new FakeMysqliResult([['in_tx' => '1']]);
        $this->assertTrue(MeelCoin::databaseInTransaction($conn), 'in_transaction = 1 berarti di dalam transaksi');
        $conn = new FakeMysqli();
        $conn->queryResult = new FakeMysqliResult([['in_tx' => '0']]);
        $this->assertFalse(MeelCoin::databaseInTransaction($conn), 'in_transaction = 0 berarti di luar transaksi');
        $this->assertSame(0, $conn->countSqlContaining('SAVEPOINT'), 'probe MariaDB tidak perlu menyentuh savepoint');
        $this->assertSame(0, $conn->countSqlContaining('INNODB_TRX'), 'probe MariaDB tidak perlu membaca INNODB_TRX');
    }
    public function testMySqlFallsBackToSavepointProbeInsideTransaction(): void
    {
        $conn = new FakeMysqliWithoutInTransaction();
        $conn->inTransaction = true;
        $this->assertTrue(
            MeelCoin::databaseInTransaction($conn),
            'savepoint yang bisa dilepas berarti sesi benar-benar di dalam transaksi'
        );
        $this->assertSame(
            2,
            $conn->countSqlContaining('SAVEPOINT `meel_tx_probe_'),
            'probe savepoint dijalankan sekali sebagai pasangan SAVEPOINT + RELEASE'
        );
        $this->assertSame(1, $conn->countSqlContaining('RELEASE SAVEPOINT `meel_tx_probe_'), 'probe harus melepas savepoint');
        $this->assertSame([], $conn->liveSavepoints, 'savepoint probe harus langsung dilepas, tidak meninggalkan sisa');
        $this->assertSame(0, $conn->countSqlContaining('INNODB_TRX'), 'savepoint sudah menjawab, tidak perlu query tambahan');
    }
    public function testMySqlFallsBackToSavepointProbeOutsideTransaction(): void
    {
        $conn = new FakeMysqliWithoutInTransaction();
        $conn->inTransaction = false;
        $this->assertFalse(
            MeelCoin::databaseInTransaction($conn),
            'RELEASE SAVEPOINT yang gagal (error 1305) berarti sesi di luar transaksi'
        );
    }
    public function testMySqlWriteTransactionIsDetectedFromInnodbTrxList(): void
    {
        $conn = new FakeMysqliWithoutInTransaction();
        $conn->inTransaction = false;
        $conn->innodbTrxRows = 1;
        $this->assertTrue(
            MeelCoin::databaseInTransaction($conn),
            'baris INNODB_TRX untuk koneksi ini menandakan transaksi aktif'
        );
        $this->assertSame(1, $conn->countSqlContaining('INNODB_TRX'), 'query INNODB_TRX harus dijalankan sebagai cadangan');
    }
    public function testProbeNeverEscapesWhenServerRejectsEveryQuery(): void
    {
        $conn = new FakeMysqliRejectingEveryProbe();
        $this->assertFalse(
            MeelCoin::databaseInTransaction($conn),
            'probe yang tidak didukung harus dianggap "tidak tahu", bukan fatal'
        );
        $this->assertGreaterThan(0, count($conn->sqls), 'probe tetap dicoba sebelum menyerah');
    }
    public function testOwnsTransactionIsTheInverseOfTheProbe(): void
    {
        $inside = new FakeMysqliWithoutInTransaction();
        $inside->inTransaction = true;
        $outside = new FakeMysqliWithoutInTransaction();
        $outside->inTransaction = false;
        $owns = new ReflectionMethod(MeelCoin::class, 'ownsTransaction');
        $owns->setAccessible(true);
        $this->assertFalse($owns->invoke(null, $inside), 'transaksi milik pemanggil tidak boleh dibuka ulang');
        $this->assertTrue($owns->invoke(null, $outside), 'di luar transaksi MeelCoin harus membuka transaksinya sendiri');
    }
}

/* reference build: MEeL-C6H9N3O3 [cc342f7db77d12aa] */
