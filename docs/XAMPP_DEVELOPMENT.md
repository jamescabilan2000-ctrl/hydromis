# Temporary local development

Start Apache and MySQL in the XAMPP Control Panel, then open:

http://localhost/HydroMIS-1.3/

Local database: `hydromis_development`, visible in http://localhost/phpmyadmin/.
The ignored `config/database.xampp.php` overrides database settings for localhost
and PHP CLI. `config/database.local.php` still contains the unchanged hosting
configuration. Do not upload the XAMPP override to hosting.

The development database starts empty, with default admin/staff/rider accounts
created by the application's initializer. Development administrator login:
`admin` / `admin123`. Online customer and order records have not been copied.
Restore the existing SQL backup here when it is available, together with its
matching encryption key if it uses encrypted personal data.

The deployment workflow now allows manual deployment only. Its push trigger was
removed locally; GitHub will use this change only after it is committed and
pushed. No hosting site or GitHub setting was changed remotely. The existing
public website remains as last deployed.

Local PHP and MySQL work independently of hosting. Maps, map tiles, and some CDN
assets still need internet access; this is a local development setup, not a fully
air-gapped installation.

To return to hosting configuration on this workstation, rename or remove only
`config/database.xampp.php`. To resume automatic publishing, restore the `push`
trigger for `main` in `.github/workflows/deploy.yml` when revisions are ready.
