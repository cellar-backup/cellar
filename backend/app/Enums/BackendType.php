<?php

namespace App\Enums;

enum BackendType: string
{
    case Local = 'local';
    case S3 = 's3';
    case B2 = 'b2';
    case R2 = 'r2';
    case GCS = 'gcs';
    case Azure = 'azure';
    case SFTP = 'sftp';
    case SMB = 'smb';
    case NFS = 'nfs';
    case Rclone = 'rclone';
}
