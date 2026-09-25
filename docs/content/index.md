---
title: Rocket Print
description: Imprimez sur les imprimantes de l'entreprise depuis le navigateur ou depuis vos applications, via Samba, IPP ou CUPS.
seo:
  title: Rocket Print — Documentation
---

::u-page-hero
---
orientation: horizontal
title: Les imprimantes de l'entreprise, à portée d'API.
---
#description
Rocket Print relie vos utilisateurs et vos applications aux imprimantes de l'entreprise : partages **Windows / Samba**, imprimantes réseau **IPP** et files **CUPS**. Files d'impression asynchrones, reprises automatiques, suivi de chaque document.

#links
  :::u-button
  ---
  to: /api/print-jobs
  size: xl
  trailing-icon: i-lucide-arrow-right
  ---
  Imprimer depuis une application
  :::

  :::u-button
  ---
  to: /getting-started/introduction
  size: xl
  color: neutral
  variant: subtle
  icon: i-lucide-book-open
  ---
  Découvrir Rocket Print
  :::

#default
  ```bash [Terminal]
  curl -X POST https://print.exemple.com/api/print-jobs \
    -H "Authorization: Bearer rpa_…" \
    -H "X-Impersonate-User: alice@exemple.com" \
    -F file=@facture.pdf \
    -F printer=0199… -F copies=2 -F duplex=1
  # → 201 { "id": "…", "status": "queued", … }
  ```
::

::u-page-section
#title
Ce que vous pouvez faire

#features
  :::u-page-feature
  ---
  icon: i-lucide-network
  to: /administration/printers
  ---
  #title
  Samba, IPP et CUPS

  #description
  Imprimantes partagées par un serveur Windows ou Samba (smbclient), imprimantes réseau et files CUPS en IPP, dossier de test.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-list-checks
  to: /printing/print
  ---
  #title
  Files d'impression asynchrones

  #description
  Chaque document passe par une file traitée par le worker : reprises automatiques, annulation, réimpression et historique.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-code
  to: /api/print-jobs
  ---
  #title
  API pour vos applications

  #description
  Vos applications impriment au nom de leurs utilisateurs (impersonation), sans jamais connaître les imprimantes ni leurs identifiants.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-activity
  to: /administration/dashboard
  ---
  #title
  Imprimantes surveillées

  #description
  Chaque imprimante active est vérifiée toutes les 5 minutes et apparaît dans l'état des services du tableau de bord.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-users
  to: /administration/users-ldap
  ---
  #title
  Comptes locaux, LDAP et SSO

  #description
  Synchronisation avec votre annuaire, connexion unique via Rocket Auth, rôle administrateur piloté par un groupe.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-lock
  to: /administration/printers#sécurité
  ---
  #title
  Identifiants protégés

  #description
  Mots de passe des imprimantes chiffrés en base, jamais renvoyés par l'API ni passés en ligne de commande.
  :::
::
