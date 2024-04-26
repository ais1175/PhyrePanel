<?php

namespace App\Models;

use App\Filament\Enums\BackupStatus;
use App\Helpers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class Backup extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'backup_type',
        'status',
        'path',
        'size',
        'disk',
    ];

    protected $casts = [
        'status' => BackupStatus::class,
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->status = 'pending';
            $model->checkCronJob();
        });

        static::created(function ($model) {
            $model->startBackup();
        });

        static::deleting(function ($model) {
           if (is_dir($model->path)) {
               shell_exec('rm -rf ' . $model->path);
           }
        });
    }

    public function checkCronJob()
    {
        $cronJobCommand = 'phyre-php /usr/local/phyre/web/artisan phyre:run-backup';
        $findCronJob = CronJob::where('command', $cronJobCommand)->first();
        if (! $findCronJob) {
            $cronJob = new CronJob();
            $cronJob->schedule = '*/5 * * * *';
            $cronJob->command = $cronJobCommand;
            $cronJob->user = 'root';
            $cronJob->save();
            return false;
        }
        return true;
    }

    public function checkBackup()
    {
        if ($this->status == BackupStatus::Processing) {

            $backupDoneFile = $this->path.'/backup.done';
            if (file_exists($backupDoneFile)) {
                $this->size = Helpers::checkPathSize($this->path);
                $this->status = 'completed';
                $this->completed = true;
                $this->completed_at = now();
                $this->save();
                return [
                    'status' => 'completed',
                    'message' => 'Backup completed'
                ];
            }

            $checkProcess = shell_exec('ps -p ' . $this->process_id . ' | grep ' . $this->process_id);
            if (Str::contains($checkProcess, $this->process_id)) {

                $this->size = Helpers::checkPathSize($this->path);
                $this->save();

                return [
                    'status' => 'processing',
                    'message' => 'Backup is still processing'
                ];
            } else {
                $this->status = 'failed';
                $this->save();
                return [
                    'status' => 'failed',
                    'message' => 'Backup failed'
                ];
            }
        }
    }

    public function startBackup()
    {
        if ($this->status == BackupStatus::Processing) {
            return [
                'status' => 'processing',
                'message' => 'Backup is already processing'
            ];
        }

        $storagePath = storage_path('backups');
        if (! is_dir($storagePath)) {
            mkdir($storagePath);
        }
        $backupPath = $storagePath.'/'.$this->backup_type.'/'.$this->id;
        if (!is_dir(dirname($backupPath))) {
            mkdir(dirname($backupPath));
        }
        if (! is_dir($backupPath)) {
            mkdir($backupPath);
        }
        $backupTempPath = $backupPath.'/temp';
        if (! is_dir($backupTempPath)) {
            mkdir($backupTempPath);
        }

        if ($this->backup_type == 'full') {

            // Export Phyre Panel database
            $databaseBackupPath = $backupTempPath.'/database.sql';

            // Export Phyre Panel files
            $backupFilePath = $backupPath.'/phyre-panel-'.date('Ymd-His').'.tar.gz';

            $backupLogFileName = 'backup.log';
            $backupLogFilePath = $backupPath.'/'.$backupLogFileName;

            $backupTempScript = '/tmp/backup-script-'.$this->id.'.sh';
            $shellFileContent = '';
            $shellFileContent .= 'echo "Backup up Phyre Panel files"'. PHP_EOL;
            $shellFileContent .= 'mysqldump -u "'.env('MYSQl_ROOT_USERNAME').'" -p"'.env('MYSQL_ROOT_PASSWORD').'" "'.env('DB_DATABASE').'" > '.$databaseBackupPath . PHP_EOL;
            $shellFileContent .= 'cd '.$backupTempPath .' && tar -czvf '.$backupFilePath.' ./* '. PHP_EOL;

            $shellFileContent .= 'rm -rf '.$backupTempPath.PHP_EOL;
            $shellFileContent .= 'echo "Backup complete"' . PHP_EOL;
            $shellFileContent .= 'touch ' . $backupPath. '/backup.done' . PHP_EOL;
            $shellFileContent .= 'rm -rf ' . $backupTempScript;

            file_put_contents($backupTempScript, $shellFileContent);

            $processId = shell_exec('bash '.$backupTempScript.' >> ' . $backupLogFilePath . ' & echo $!');
            $processId = intval($processId);

            if ($processId > 0 && is_numeric($processId)) {

                $this->path = $backupPath;
                $this->filepath = $backupFilePath;
                $this->status = 'processing';
                $this->queued = true;
                $this->queued_at = now();
                $this->process_id = $processId;
                $this->save();

                return [
                    'status' => 'processing',
                    'message' => 'System backup started'
                ];
            } else {
                $this->status = 'failed';
                $this->save();
                return [
                    'status' => 'failed',
                    'message' => 'System backup failed to start'
                ];
            }

        }
    }
}
