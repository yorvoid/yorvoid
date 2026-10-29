# Move to PostgreSQL

SQLite is for development and tests. In production, YorvoID's tables go in PostgreSQL 16 or later, next to your application's.

1. Point the connection at PostgreSQL:

   ```dotenv
   DATABASE_URL="postgresql://app:secret@db:5432/app?serverVersion=17&charset=utf8"
   ```

2. Optionally, give YorvoID's tables a schema of their own:

   ```yaml
   # config/packages/yorvoid.yaml
   yorvoid:
       storage:
           schema: auth
   ```

3. Keep your own migrations away from YorvoID's tables, so a schema diff never tries to drop them:

   ```yaml
   # config/packages/doctrine.yaml
   doctrine:
       dbal:
           schema_filter: '~^(?!yorvoid_)~'
   ```

   With a schema of its own, filter `~^(?!auth\.)~` instead.

4. Create the tables, and check:

   ```bash
   php bin/console yorvoid:migrate
   php bin/console yorvoid:doctor
   ```

YorvoID doesn't copy data between databases: start production with a fresh installation, and [claim it](../tutorials/symfony-quickstart.md#_8-claim-the-first-account) with a setup code. How the tables are named and migrated is in [storage](../concepts/storage.md).
