---
title: Rocket Cast
description: Affichage dynamique pour vos lieux — écrans, TV et tablettes pilotés par des playlists et alimentés par Rocket PMS, Rocket Place ou n'importe quelle API JSON.
seo:
  title: Rocket Cast — Documentation
---

::u-page-hero
---
orientation: horizontal
title: Vos écrans, votre contenu, sans boîtier.
---
#description
Rocket Cast diffuse des **playlists** sur les TV, écrans et tablettes de vos lieux : un simple navigateur ouvert sur un lien. Accueil des voyageurs, Wi-Fi, météo, horloge, départ, images, QR codes, et des **panneaux alimentés par vos intégrations** (Rocket PMS, Rocket Place, Web JSON).

#links
  :::u-button
  ---
  to: /getting-started/introduction
  size: xl
  trailing-icon: i-lucide-arrow-right
  ---
  Découvrir Rocket Cast
  :::

  :::u-button
  ---
  to: /display/screens
  size: xl
  color: neutral
  variant: subtle
  icon: i-lucide-monitor
  ---
  Installer un écran
  :::

#default
  ```text [Sur la TV]
  https://cast.exemple.com/pair

  Code d'appairage de cet écran
          K7F-3QX
  ```
::

::u-page-section
#title
Ce que vous pouvez faire

#features
  :::u-page-feature
  ---
  icon: i-lucide-link
  to: /display/screens
  ---
  #title
  Écrans appairés en 10 secondes

  #description
  Ouvrez /pair sur la TV, saisissez le code dans l'administration : l'écran reçoit son lien secret et affiche sa playlist.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-list-video
  to: /display/playlists
  ---
  #title
  Playlists programmées

  #description
  Panneaux avec durée et programmation (jours, heures, nuit), éditeur avec aperçu en direct, fuseau horaire par écran.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-plug
  to: /display/sources
  ---
  #title
  Sources branchables

  #description
  Livret d'accueil et prénom du voyageur (Rocket PMS), valeurs domotiques (Rocket Place), n'importe quelle API JSON.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-wifi-off
  to: /display/screens#hors-ligne
  ---
  #title
  Tolérant aux coupures

  #description
  L'écran garde le dernier contenu reçu et le rejoue tant que le réseau ou une source est indisponible.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-lock
  to: /display/sources#identifiants
  ---
  #title
  Identifiants chiffrés

  #description
  Jetons des sources dans le coffre des secrets de rocket-core, jamais renvoyés par l'API ; liens d'écran révocables.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-users
  to: /administration/users-ldap
  ---
  #title
  Comptes locaux, LDAP et SSO

  #description
  Le socle Rocket : annuaire, connexion unique via Rocket Auth, applications, tableau de bord et mises à jour.
  :::
::
