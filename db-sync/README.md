# Database Sync Utility

A bash script to sync a remote MySQL database to your local development environment via SSH.

## Features

- Connects to remote server via SSH (no direct MySQL port exposure needed)
- Runs `mysqldump` on the remote server (avoids local MySQL version compatibility issues)
- Automatically drops and recreates local database
- Cleans up temp files on completion
- Colored output for easy status tracking

## Requirements

- SSH key-based authentication to remote server
- MySQL client installed locally
- `mysqldump` available on remote server

## Installation

### Bash Script

```bash
# Symlink to your project
ln -s ~/Sites/mox3-utils/db-sync/sync-db.sh ./sync-db.sh

# Or copy
cp ~/Sites/mox3-utils/db-sync/sync-db.sh ./sync-db.sh
chmod +x ./sync-db.sh
```

### Laravel Artisan Command

```bash
cp ~/Sites/mox3-utils/db-sync/SyncProductionDatabase.php app/Console/Commands/
```

Add these to your `.env`:

```env
PRODUCTION_DB_HOST=127.0.0.1
PRODUCTION_DB_DATABASE=your_db_name
PRODUCTION_DB_USERNAME=forge
PRODUCTION_DB_PASSWORD=your_password

PRODUCTION_SSH_HOST=your-server-ip
PRODUCTION_SSH_USER=forge
```

Add a `production` connection in `config/database.php`:

```php
'production' => [
    'driver' => env('PRODUCTION_DB_DRIVER', 'mysql'),
    'host' => env('PRODUCTION_DB_HOST', '127.0.0.1'),
    'port' => env('PRODUCTION_DB_PORT', '3306'),
    'database' => env('PRODUCTION_DB_DATABASE'),
    'username' => env('PRODUCTION_DB_USERNAME'),
    'password' => env('PRODUCTION_DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
],
```

Then run:

```bash
php artisan db:sync-production
php artisan db:sync-production --force    # Skip confirmation
php artisan db:sync-production --backup   # Backup local DB first
```

## Configuration

Create a `.db-sync.conf` file in your project root:

```bash
# Copy the example config
cp ~/Sites/mox3-utils/db-sync/.db-sync.conf.example ./.db-sync.conf
```

Edit `.db-sync.conf` with your connection details:

```bash
# SSH Configuration
SSH_HOST="your-server.com"
SSH_PORT="22"
SSH_USER="your-ssh-user"

# Remote MySQL Configuration
REMOTE_DB_HOST="127.0.0.1"
REMOTE_DB_USER="db_username"
REMOTE_DB_PASS="db_password"
REMOTE_DB_NAME="production_db_name"

# Local MySQL Configuration
LOCAL_DB_HOST="127.0.0.1"
LOCAL_DB_PORT="3306"
LOCAL_DB_USER="root"
LOCAL_DB_PASS=""
LOCAL_DB_NAME="local_db_name"
```

**Important:** Add `.db-sync.conf` to your `.gitignore` to avoid committing credentials!

```bash
echo ".db-sync.conf" >> .gitignore
```

## Usage

### Basic usage (uses .db-sync.conf in current directory)

```bash
./sync-db.sh
```

### Specify a config file

```bash
./sync-db.sh /path/to/custom-config.conf
```

### Example output

```
╔════════════════════════════════════════╗
║      Database Sync Utility             ║
╚════════════════════════════════════════╝

Remote: master_user@146.190.119.63 → production_db
Local:  root@127.0.0.1 → local_db

[1/4] Testing SSH connection...
✓ SSH connection successful
[2/4] Dumping remote database via SSH...
       (this may take a few minutes for large databases)
✓ Database dump complete (6.9M)
[3/4] Resetting local database...
✓ Local database reset
[4/4] Importing to local database...
✓ Import complete

╔════════════════════════════════════════╗
║           Sync Complete!               ║
╚════════════════════════════════════════╝
Database 'production_db' synced to local 'local_db'
```

## Troubleshooting

### SSH Connection Failed

```bash
# Test SSH connection manually
ssh your-user@your-server.com -p 22

# Check if your SSH key is added
ssh-add -l

# Add your SSH key if needed
ssh-add ~/.ssh/id_rsa
```

### MySQL Authentication Error

Make sure the MySQL credentials in your config are correct. Test on the remote server:

```bash
ssh your-user@your-server.com
mysql -u db_user -p db_name -e "SELECT 1"
```

### Empty Dump File

Check if `mysqldump` is available on the remote server and the database exists:

```bash
ssh your-user@your-server.com "which mysqldump"
ssh your-user@your-server.com "mysql -u db_user -p -e 'SHOW DATABASES'"
```

## Security Notes

1. **Never commit `.db-sync.conf`** - Add it to `.gitignore`
2. **Use SSH keys** - Password authentication is not supported
3. **Restrict database user permissions** - Use a read-only user if possible

## License

MIT
