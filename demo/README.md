# Environnement de démo

## Dans GitHub Codespaces (rien à installer)

1. Sur GitHub, ouvre le dépôt, choisis la branche qui contient la démo, puis **Code → Codespaces → Create codespace on …**.
2. Attends la fin de la commande de démarrage dans le terminal (5 à 10 minutes au premier lancement, le temps de construire les images). Elle affiche les URLs de la démo.
3. Dans l'onglet **Ports**, ouvre « Rocket Print » (3300) ou « Documentation et changelog » (3301).

> ⚠️ Les mots de passe de démo sont publics. Arrête le codespace quand tu as fini (menu Codespaces → *Stop codespace*).

Pour relancer la démo à la main : `bash demo/codespaces/start.sh`.

## En local

Pré-requis : Docker avec Compose v2.24 ou plus récent.

```bash
docker compose -f compose.yaml -f compose.demo.yaml up -d --build
```

Le service `demo-seed` prépare la base, charge les données de démo et synchronise l'annuaire LDAP, puis s'arrête : `docker compose -f compose.yaml -f compose.demo.yaml logs -f demo-seed`.

| Adresse | Contenu |
|---|---|
| http://localhost:3300 | Rocket Print |
| http://localhost:3301 | Documentation, et le changelog sur `/changelog` |
| http://localhost:8300/api/docs | Documentation de l'API |

Le service `print-server` est un vrai serveur d'impression **Samba** : ce qu'on imprime sur « Laser 2e étage (Samba) » arrive dans son dossier `/printed` au lieu de sortir sur papier.

## Comptes

| Compte | Mot de passe | Type |
|---|---|---|
| `admin@example.org` | `demo-admin-password` | local, administrateur |
| `alice@example.org` | `demo-alice-password` | local |
| `marie.martin@example.org` | `password` | LDAP, administratrice via le groupe `rocket-admins` |
| `jean.dupont@example.org` | `password` | LDAP |

## Scénarios à tester

1. **Imprimer.** Connecte-toi avec `alice@example.org`, ouvre *Imprimer*, dépose un PDF, choisis « Laser 2e étage (Samba) » et 2 exemplaires. Dans *Mes impressions*, le document passe en « Imprimé ». Vérifie qu'il est arrivé :
   ```bash
   docker compose -f compose.yaml -f compose.demo.yaml exec print-server ls -l /printed
   ```
2. **Panne et reprise.** `docker compose -f compose.yaml -f compose.demo.yaml stop print-server`, puis imprime : le document attend entre les tentatives (1 puis 5 minutes), puis passe en « Échec ». Redémarre le serveur (`start print-server`) et clique sur *Réimprimer*. Le tableau de bord de l'admin montre l'imprimante en échec pendant la panne.
3. **Administration.** Avec `admin@example.org`, ouvre *Administration → Imprimantes* : *Tester la connexion*, *Imprimer une page de test*, ajoute une imprimante (Samba, IPP ou dossier).
4. **Application et impersonation.** Une application imprime au nom d'Alice :
   ```bash
   curl -X POST http://localhost:3300/api/print-jobs \
     -H "Authorization: Bearer rpa_demo_rocket_print_do_not_use_in_production" \
     -H "X-Impersonate-User: alice@example.org" -H "Accept: application/json" \
     -F file=@document.pdf
   ```
   Le document apparaît dans *Mes impressions* d'Alice, avec le nom de l'application. En impersonnant `admin@example.org`, l'application n'obtient pas pour autant les droits administrateur.
5. **Connexion LDAP.** Connecte-toi avec `marie.martin@example.org` / `password` : elle est administratrice grâce à son groupe LDAP.

## Réinitialiser

```bash
docker compose -f compose.yaml -f compose.demo.yaml down -v
```
