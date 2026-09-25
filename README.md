# Rocket Print

Impression vers les imprimantes de l'entreprise, depuis le navigateur ou depuis vos applications : partages **Windows / Samba**, imprimantes réseau et files **CUPS** en **IPP**, files d'impression asynchrones avec reprises et suivi. Brique du Middleware Rocket, sur la même stack que [Rocket Mailer](https://github.com/fayouz/rocket-mailer), [Rocket Auth](https://github.com/fayouz/rocket-auth) et [Rocket Cloud](https://github.com/fayouz/rocket-cloud).

| Dossier | Stack |
|---|---|
| `backend/` | Symfony 8.1, API Platform 5, Doctrine ORM 3 (PostgreSQL), StofDoctrineExtensions, LexikJWT, Messenger et Scheduler, LDAP, `smbclient` |
| `frontend/` | Nuxt 4, Nuxt UI 4 |
| `docs/` | Site de documentation (Nuxt UI + Nuxt Content), avec le changelog sur `/changelog` : `cd docs && npm install && npm run dev`, puis http://localhost:3301 |
| `docker/print-server/` | Serveur d'impression Samba de la démo |

Le socle commun (comptes, LDAP, SSO, applications, tableau de bord, mises à jour, modes autonome et suite) vient de **[rocket-core](https://github.com/fayouz/rocket-core)** : le bundle Symfony `rocket/core-bundle` (Composer) et le layer Nuxt `@rocket/core` (npm). Pour travailler sur les deux à la fois : `ROCKET_CORE_LAYER=../../rocket-core/nuxt npm run dev` côté front, et un dépôt `path` Composer côté backend.

## Démarrage rapide

```bash
docker compose up -d --build
```

Au premier lancement, http://localhost:3300 affiche la **configuration initiale** : on y crée le compte administrateur. Si l'instance est exposée avant d'être configurée, définissez `SETUP_TOKEN`. L'administrateur peut aussi être créé en ligne de commande : `docker compose exec api php bin/console app:user:create admin@example.org 'un-mot-de-passe-long' --admin`.

Déclarez ensuite les imprimantes dans **Administration → Imprimantes**.

- Application : http://localhost:3300
- API + documentation OpenAPI : http://localhost:8300/api/docs

### Démo prête à tester

`docker compose -f compose.yaml -f compose.demo.yaml up -d --build` lance une démo complète : comptes locaux et LDAP, une imprimante « dossier », et un **vrai serveur d'impression Samba** sur lequel imprimer. Voir [demo/README.md](demo/README.md).

### Développement sans Docker

```bash
# backend (PHP 8.4, PostgreSQL, smbclient pour le connecteur Samba)
cd backend && composer install
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:migrations:migrate
echo 'MESSENGER_TRANSPORT_DSN=sync://' >> .env.local   # ou lancer messenger:consume async scheduler_default
php -S 127.0.0.1:8300 -t public
php bin/phpunit

# frontend
cd frontend && npm install && npm run dev -- --port 3300   # NUXT_PUBLIC_API_BASE=http://localhost:8300
```

## Fonctionnalités

### Imprimantes et connecteurs
Administration → **Imprimantes** : nom, emplacement, recto verso, couleur, imprimante par défaut, activation, et un connecteur :

| Connecteur | Adresse | Principe |
|---|---|---|
| Partage Windows / Samba | `//serveur/imprimante` (`smb://…`, `\\serveur\imprimante`) | `smbclient … -c 'print …'`, compte de service et domaine. Le document est transmis tel quel (PDF, PostScript ou PCL selon l'imprimante). |
| IPP / CUPS | `ipp://imprimante/ipp/print`, `ipps://…`, `ipp://cups:631/printers/file` | Print-Job IPP/2.0 avec exemplaires, recto verso et couleur ; état et modèle par Get-Printer-Attributes. |
| Dossier | `tests` (sous `PRINT_FOLDER_ROOT`) | Écrit le document et un `.json` de ses options : tests, démo, archivage. |

**Tester la connexion** (sans imprimer) et **Imprimer une page de test** (PDF généré). Les mots de passe sont chiffrés en base (`SECRETS_ENCRYPTION_KEY`), jamais renvoyés par l'API, et passés à `smbclient` par un fichier temporaire `0600`, jamais en ligne de commande. Les imprimantes actives sont vérifiées toutes les 5 minutes (état des services du tableau de bord).

### Impression et files d'attente
- **Imprimer** : glisser-déposer, imprimante (par défaut présélectionnée), exemplaires, recto verso, couleur. Formats : PDF, PostScript, PCL, JPEG, PNG, texte ; `PRINT_MAX_FILE_SIZE` (50 Mo).
- Le **worker** envoie les documents. Échec passager : nouvelle tentative 1 puis 5 minutes plus tard (3 au total) ; échec définitif (identifiants, format refusé) : arrêt immédiat avec l'erreur.
- **Mes impressions** : statut en direct, recherche, filtres, voir le document, annuler (en attente), réimprimer (échec ou annulé). Les administrateurs voient **Toutes les impressions**.
- Documents supprimés après `PRINT_RETENTION_DAYS` jours (7) ; l'historique reste.

### API pour les applications
```bash
curl -X POST https://print.exemple.com/api/print-jobs \
  -H "Authorization: Bearer rpa_…" -H "X-Impersonate-User: alice@exemple.com" -H "Accept: application/json" \
  -F file=@facture.pdf -F printer=<id> -F copies=2 -F duplex=1
```
`GET /api/printers`, `GET /api/print-jobs[/{id}]`, `POST /api/print-jobs/{id}/cancel|retry`, `GET /api/print-jobs/{id}/content`, et pour les administrateurs `/api/admin/printers` (CRUD, `check`, `test-page`). Voir `docs/content/4.api/2.print-jobs.md`.

### Socle commun Rocket (rocket-core)
- **Comptes** locaux, **LDAP** (synchronisation, rôle admin par groupe) et **SSO OpenID Connect** (Rocket Auth ou tout fournisseur).
- **Applications externes** : jeton `rpa_…` (seul son hash est stocké) et impersonation par `X-Impersonate-User`, jamais avec le rôle administrateur.
- **Tableau de bord** : impressions, taux de réussite, file, échecs, état des services (base, tâches de fond, LDAP, SSO, imprimantes, stockage).
- **Version et mises à jour** : Docker (Watchtower, profil `updater`), serveur sans Docker (`deploy/update.sh`) ou manuelle.
- **Traçabilité** : toutes les entités sont Timestampable et Blameable.

## CI/CD

`.github/workflows/ci.yml` :
- à chaque push et pull request : lint du container, validation du schéma Doctrine, PHPUnit, puis ESLint, typecheck et build du front et de la documentation ; la démo complète est lancée et on y imprime sur le serveur Samba ;
- sur `main`, `develop` et les tags `v*` : images `ghcr.io/fayouz/rocket-print-api` et `ghcr.io/fayouz/rocket-print-front`.

Le worker utilise l'image API avec `php bin/console messenger:consume async scheduler_default`.

## Gitflow

- `main` : production (images `latest` et tags `vX.Y.Z`) ; `develop` : intégration.
- `feature/*` : pull request vers `develop`, qui complète la section `[Non publié]` de [CHANGELOG.md](CHANGELOG.md), publiée sur `/changelog` dans la documentation.
- `release/*` et `hotfix/*` vers `main` : `[Non publié]` devient `[X.Y.Z] - date`, puis tag `vX.Y.Z`.
