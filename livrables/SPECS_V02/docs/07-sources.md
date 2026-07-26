# Sources

## Sources primaires auditées

- COMPTA local :
  `/home/amelo/Documents/DEV/Ecol_WebeLi/web/compta`
- CMS Vue de référence :
  `/home/amelo/Documents/DEV/Ecol_WebeLi/web/mod/frontend/admin-vue`
- OCAS, source des taux et règles de charges salariales configurée par
  `OCAS_DB_PATH`
- Gäld, code source :
  <https://github.com/Scanix/Gaeld/tree/3b8811d7da2d4c02b28b812fc056eca0532039f4>
- Gäld, documentation :
  <https://docs.gaeld.ch/>

## Fichiers déterminants

COMPTA : migrations 001–010, `EntryService`, `ReportingService`,
`BankImportService`, `ReconciliationService`, services TVA, facturation, paie
et pédagogie, `WebApplication.php`, templates et `tests/run.php`.

OCAS : `lib/calc.php`, `lib/db.php`, `views/taux.php`,
`tests/calc_test.php` et, lorsqu'elle est fournie, la table
`taux_par_annee` de la base configurée par `OCAS_DB_PATH`.

Gäld : `app/Domains`, `resources/js`, `routes/web`, `database/migrations`,
`tests`, `composer.json`, `package.json` et `.github/workflows/ci.yml`.

Les règles légales, taux et formats officiels doivent être revérifiés au moment
de chaque lot réglementaire auprès de l'AFC, de Swissdec et de SIX. Les présents
prompts interdisent de transformer une valeur observée en constante éternelle.
