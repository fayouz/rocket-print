# Changelog

Toutes les évolutions notables de Rocket Print. Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté

- Mode suite : déconnexion depuis Rocket Auth (back-channel logout). Se déconnecter de Rocket Auth, ou y être désactivé, ferme les sessions dans Rocket Print (`ROCKET_INTERNAL_URL`).

## [0.1.0] - 2026-09-25

Première version de Rocket Print, la brique d'impression du Middleware Rocket.

### Ajouté

- **Imprimantes** (Administration → Imprimantes) : nom, emplacement, description, recto verso, couleur, imprimante par défaut, activation. Trois connecteurs :
  - **Partage Windows / Samba** par `smbclient` (`//serveur/imprimante`, `smb://…`, `\\serveur\imprimante`), avec compte de service et domaine ; identifiants passés par un fichier temporaire (`0600`), jamais en ligne de commande ;
  - **IPP / CUPS** (`ipp://`, `ipps://`, `http(s)://`) : Print-Job avec exemplaires, recto verso et couleur, Get-Printer-Attributes pour l'état et le modèle, authentification HTTP Basic ;
  - **Dossier** : documents et options écrits sous `PRINT_FOLDER_ROOT`, pour les tests, la démo ou l'archivage.
  - **Tester la connexion** et **Imprimer une page de test** (PDF généré) ; mots de passe chiffrés en base et jamais renvoyés par l'API.
- **Impression** : page **Imprimer** (dépôt par glisser-déposer, choix de l'imprimante, exemplaires, recto verso, couleur) et **Mes impressions** (statut en direct, recherche, filtres, voir le document, annuler, réimprimer). Les administrateurs voient **Toutes les impressions**.
- **Files d'impression asynchrones** : le worker envoie les documents ; les échecs passagers sont retentés après 1 puis 5 minutes (3 tentatives), les échecs définitifs (identifiants, format refusé) s'arrêtent tout de suite avec l'erreur. Les documents sont supprimés après `PRINT_RETENTION_DAYS` jours (7) et les travaux interrompus en cours d'impression passent en échec.
- **API** : `GET /api/printers`, `POST /api/print-jobs` (multipart), `GET /api/print-jobs` (`?all=1` pour les administrateurs), `GET /api/print-jobs/{id}`, `POST /api/print-jobs/{id}/cancel`, `POST /api/print-jobs/{id}/retry`, `GET /api/print-jobs/{id}/content`, `GET /api/print-jobs/settings`, et `/api/admin/printers` (CRUD, `check`, `test-page`). Les applications impriment au nom d'un utilisateur (`X-Impersonate-User`).
- **Formats** : PDF, PostScript, PCL, JPEG, PNG et texte ; taille maximale `PRINT_MAX_FILE_SIZE` (50 Mo).
- **Tableau de bord** : impressions et exemplaires sur 30 jours, taux de réussite, file d'impression, échecs, dernières impressions, impressions en échec dans l'activité. Les imprimantes actives sont vérifiées toutes les 5 minutes et apparaissent dans l'état des services.
- **Démo** : un vrai serveur d'impression Samba (`docker/print-server`), une imprimante « dossier », des impressions d'Alice ; la CI imprime sur le serveur Samba de la démo.
- **Socle commun Rocket**, partagé avec Rocket Mailer, Rocket Auth et Rocket Cloud : configuration initiale, comptes locaux, LDAP et SSO (OpenID Connect, Rocket Auth), serveurs d'authentification, applications externes et impersonation, tableau de bord extensible, sondes de santé, version et mises à jour (Docker, serveur sans Docker, manuelle), environnement de démo et Codespaces.

### Corrigé

- État des tâches de fond : un message différé (nouvelle tentative d'impression) n'est plus compté en retard avant son heure.
