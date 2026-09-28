# Changelog

Toutes les évolutions notables de Rocket Cast. Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

### Modifié
- **Identifiants des sources dans le coffre de rocket-core** (0.3.1, Administration → Secrets) : `SecretStoreInterface` est implémentée par `VaultSecretStore` (secret `cast.credentials.<aléatoire>`, référence `vault:<nom>` en base, supprimé quand la source ou l'appairage disparaît) au lieu du chiffrement libsodium propre. `ROCKET_SECRETS_KEY` devient la clé du coffre (base64 de 32 octets, `rocket:secrets:generate-key`) ; jamais de valeur renvoyée par l'API.

### Ajouté
- Commande `app:secrets:migrate [--dry-run]` : déplace les identifiants de l'ancien format (« v1: », ancienne clé dans `CAST_LEGACY_SECRETS_KEY`, ou `APP_SECRET`) dans le coffre et supprime les secrets orphelins ; idempotente.

## [0.2.1] - 2026-09-28

### Modifié
- CI : images Docker publiées sur ghcr.io uniquement sur tag `v*` et lancement manuel, multi-arch amd64/arm64, SBOM et provenance (workflow `docker-images.yml` de rocket-core) ; image API sur FrankenPHP Alpine sans Composer, `HEALTHCHECK` API et front ; exemple `compose.prod.yaml`.

## [0.2.0] - 2026-09-28

### Ajouté

- **Lieu d'un écran** : `placeId` (lieu Rocket Place, facultatif) et `placeName` (nom en cache), modifiables dans **Écrans** ; filtre `GET /api/screens?place=<uuid>`. L'écran de démo est rattaché au lieu de démo « Le port » (`0192f7c4-0000-7000-8000-000000000001`), qui sert aussi à la source Rocket Place de démo.

## [0.1.0] - 2026-09-28

Première version de Rocket Cast, la brique d'affichage dynamique du Middleware Rocket.

### Ajouté

- **Écrans** : lien secret `/s/<jeton>` (256 bits, seule l'empreinte est stockée, affiché une fois), nouveau lien, révocation, orientation (portrait pivoté sur une TV en paysage), fuseau horaire, langue des dates, activation, présence (dernière visite, navigateur, en ligne).
- **Appairage** : un écran neuf ouvre `/pair` et affiche un code de 6 caractères (15 minutes) ; saisi dans **Écrans → Appairer un écran**, il relie l'écran à un nouvel écran ou à un écran existant, qui reçoit son lien une seule fois.
- **Playlists** : panneaux avec durée, activation et programmation (jours, heures, plages de nuit) dans le fuseau de l'écran ; couleur d'accent, thème clair ou sombre ; duplication ; éditeur avec **aperçu en direct** (panneau sélectionné ou boucle actuelle, orientation, fuseau).
- **Panneaux** : message d'accueil, horloge, météo (Open-Meteo, lue par le navigateur), Wi-Fi avec QR code de connexion, départ, image (`https://` ou Rocket Cloud), texte mis en forme, QR code, et panneaux **source**.
- **Sources** branchables (`SourceTypeInterface`) : **Rocket PMS** (livret d'accueil par le lien « écran TV » : accueil au prénom du voyageur, voyageur présent, prochaine arrivée, Wi-Fi, départ, règlement, bonnes adresses, contacts ; relecture 30 minutes avant chaque arrivée), **Rocket Place** (valeurs domotiques d'un lieu, jeton d'application), **Web JSON** (valeurs par chemin, texte, en-tête Authorization). Cache par source, dernières données gardées en cas de panne, **Lire maintenant**.
- **Identifiants des sources chiffrés en base** (libsodium, `ROCKET_SECRETS_KEY`), jamais renvoyés par l'API, derrière `SecretStoreInterface` pour le futur coffre de rocket-core.
- **Kiosque** : plein écran, fondu entre panneaux, barre de progression, relecture chaque minute et à chaque changement de programmation (`reloadAt`), **hors ligne** avec le dernier contenu reçu, Wake Lock, rechargement quotidien.
- **API publique** des écrans et de l'appairage : limitée par IP (120 requêtes par minute, 10 jetons invalides), `Cache-Control: no-store`, `X-Robots-Tag: noindex`.
- **Tableau de bord** : écrans en ligne, playlists, sources en erreur, derniers écrans vus ; sources vérifiées toutes les 5 minutes (état des services).
- **Démo** : trois sources servies par l'API elle-même, une playlist qui utilise tous les panneaux, un écran au lien connu ; scénarios vérifiés par la CI.
- Socle rocket-core : comptes locaux, LDAP et SSO, mode suite (Rocket Auth, back-channel logout), applications (`rct_…`), mises à jour.
