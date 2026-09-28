# Rocket Cast

Affichage dynamique (digital signage) du Middleware Rocket : des **playlists** de panneaux diffusées sur les TV, écrans et tablettes de vos lieux, par un simple navigateur ouvert sur un lien secret. Panneaux d'accueil, horloge, météo, Wi-Fi, départ, image, texte, QR code, et **panneaux alimentés par des sources** : Rocket PMS (livret d'accueil, prénom du voyageur, prochaine arrivée), Rocket Place (valeurs domotiques), n'importe quelle API JSON.

Construit sur [rocket-core](https://github.com/fayouz/rocket-core) (bundle Symfony `rocket/core-bundle` et layer Nuxt `@rocket/core`) : comptes, LDAP, SSO, applications, tableau de bord et mises à jour viennent du socle.

| Dossier | Contenu |
|---|---|
| `backend/` | API Symfony 8.1 + API Platform, PostgreSQL, worker Messenger/Scheduler |
| `frontend/` | Nuxt 4 + Nuxt UI 4 : administration, kiosque `/s/<jeton>`, appairage `/pair` |
| `docs/` | Documentation Nuxt Content (et changelog) |
| `demo/`, `compose.demo.yaml` | Démo complète (Codespaces ou local) |

## Démarrer

```bash
docker compose up -d --build        # http://localhost:3600 (API : 8600)
```

La page **Configuration initiale** crée l'administrateur. Puis : **Sources** (intégrations), **Playlists**, **Écrans** → *Appairer un écran* avec le code affiché par `/pair` sur la TV.

`docker compose -f compose.yaml -f compose.demo.yaml up -d --build` lance une démo complète : comptes locaux et LDAP, trois sources de démonstration servies par l'API, une playlist qui utilise tous les panneaux et l'écran http://localhost:3600/s/demo-screen-rocket-cast-0000000000000000000. Voir [demo/README.md](demo/README.md).

## Développement local

```bash
docker run -d --name rocket-cast-db -e POSTGRES_USER=app -e POSTGRES_PASSWORD=app -e POSTGRES_DB=app -p 127.0.0.1:55438:5432 postgres:16-alpine
cd backend
echo 'DATABASE_URL="postgresql://app:app@127.0.0.1:55438/app?serverVersion=16&charset=utf8"' > .env.local
cp .env.local .env.test.local
echo "ROCKET_SECRETS_KEY=$(openssl rand -base64 32)" >> .env.local
composer install && php bin/console lexik:jwt:generate-keypair --skip-if-exists
php bin/console doctrine:migrations:migrate -n && php bin/console app:demo:seed   # avec DEMO_MODE=1 dans .env.local pour les sources de démo
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8600 -t public
cd ../frontend && npm install && NUXT_PUBLIC_API_BASE=http://localhost:8600 npm run dev -- --port 3600
```

`PHP_CLI_SERVER_WORKERS` : les sources de démo sont servies par l'API elle-même.

## Domaine

- **Écrans** : jeton de 256 bits (empreinte SHA-256 seule en base, lien affiché une fois), nouveau lien, révocation, appairage par code (`/pair`), orientation (portrait pivoté sur une TV en paysage), fuseau horaire, langue, présence (`lastSeenAt`, en ligne si vu depuis 3 minutes).
- **Playlists** : panneaux JSON validés (`App\Cast\Panels`) avec durée, activation et programmation (jours, heures, nuit) dans le fuseau de l'écran ; éditeur avec aperçu en direct (`POST /api/playlists/preview`).
- **Sources** : intégrations branchables (`App\Source\SourceTypeInterface`, tag `cast.source_type`) avec leurs vues, leurs champs et un cache (`refreshSeconds`, `reloadAt`) ; les dernières données sont gardées en cas de panne. Identifiants dans le **coffre des secrets de rocket-core** (`App\Secret\SecretStoreInterface` → `VaultSecretStore`, secrets `cast.credentials.*`, clé `ROCKET_SECRETS_KEY`), jamais dans `.env`, jamais renvoyés.
- **Kiosque** `/s/<jeton>` : plein écran, boucle avec fondu, relecture chaque minute et à `reloadAt`, dernier contenu gardé hors ligne, Wake Lock ; API publique `GET /api/public/screens/{jeton}` limitée par IP, `no-store`, `noindex`.
- **Tableau de bord** : écrans en ligne, playlists, sources en erreur ; les sources sont vérifiées toutes les 5 minutes (état des services).

Contrat de la source Rocket PMS : [docs/content/4.api/3.rocket-pms.md](docs/content/4.api/3.rocket-pms.md).

## Variables propres à Rocket Cast

| Variable | Rôle |
|---|---|
| `ROCKET_SECRETS_KEY` | Clé maîtresse du coffre des secrets de rocket-core (base64 de 32 octets, `php bin/console rocket:secrets:generate-key`), qui garde les identifiants des sources |
| `CAST_LEGACY_SECRETS_KEY` | Transition : ancienne valeur hexadécimale de `ROCKET_SECRETS_KEY`, le temps de `app:secrets:migrate` |
| `CAST_SOURCE_TIMEOUT` | Délai des requêtes aux sources (s), 5 |
| `CAST_CLOUD_URL` | Origine Rocket Cloud acceptée par les panneaux image, en plus de `https://` |
| `FRONTEND_URL` | Adresse du front, pour les liens d'écran |
| `DEMO_SCREEN_TOKEN`, `DEMO_SOURCE_BASE_URL` | Démo : jeton connu de l'écran, adresse de l'API vue par elle-même |

Les autres variables (base, JWT, LDAP, suite Rocket, mises à jour) sont celles du socle : voir [docs/content/1.getting-started/2.installation.md](docs/content/1.getting-started/2.installation.md).

## Secrets (coffre de rocket-core)

Les identifiants des sources (jetons, clés, en-têtes) et les jetons de kiosque en attente d'appairage sont gardés dans le coffre de rocket-core (Administration → **Secrets**) : `App\Secret\VaultSecretStore` (derrière `SecretStoreInterface`) enregistre chaque jeu d'identifiants comme secret `cast.credentials.<aléatoire>` et la source ne garde que la référence `vault:<nom>`. L'API ne renvoie jamais les valeurs. Rocket Cast n'a pas d'autre secret d'intégration dans l'environnement.

Migration d'une instance existante (identifiants « v1: » chiffrés par l'ancien `SodiumSecretStore`) :

1. Déplacer l'ancienne valeur de `ROCKET_SECRETS_KEY` dans `CAST_LEGACY_SECRETS_KEY` (vide avant : rien à faire, l'ancienne clé était dérivée de `APP_SECRET`, toujours essayé).
2. `php bin/console rocket:secrets:generate-key` → nouvelle `ROCKET_SECRETS_KEY` ; `php bin/console doctrine:migrations:migrate`.
3. `php bin/console app:secrets:migrate --dry-run` puis `php bin/console app:secrets:migrate` : déplace les identifiants dans le coffre et supprime les secrets `cast.credentials.*` orphelins (idempotent). Les appairages en attente de l'ancien format sont supprimés (l'écran redemande un code).
4. Retirer `CAST_LEGACY_SECRETS_KEY`.

## CI/CD

`.github/workflows/ci.yml` appelle les workflows réutilisables de rocket-core :
- à chaque push et pull request : lint du container, validation du schéma Doctrine, PHPUnit, puis ESLint, typecheck et build du front et de la documentation ; la démo complète est lancée et ses scénarios vérifiés (`.github/demo-scenarios.sh`) ;
- sur `main`, `develop` et les tags `v*` : images `ghcr.io/fayouz/rocket-cast-api` et `ghcr.io/fayouz/rocket-cast-front`.

Le worker utilise l'image API avec `php bin/console messenger:consume async scheduler_default`.

## Images Docker

Publiées par la CI (workflow réutilisable `docker-images.yml` de rocket-core) **uniquement** sur tag `vX.Y.Z` et lancement manuel (Actions → CI → Run workflow) :

| Image | Contenu |
| --- | --- |
| `ghcr.io/fayouz/rocket-cast-api` | API Symfony + worker (FrankenPHP Alpine, `composer --no-dev`, opcache, cible `prod` de `backend/Dockerfile`) |
| `ghcr.io/fayouz/rocket-cast-front` | Front Nuxt (`.output` seul, `node:22-alpine`, utilisateur `node`, cible `prod` de `frontend/Dockerfile`) |

- Tags : `vX.Y.Z`, `X.Y.Z`, `X.Y`, `latest` (dernier tag) et `sha-<commit>` ; multi-arch `linux/amd64` + `linux/arm64` ; labels OCI (source, version, révision), SBOM et provenance.
- Sur les PR et branches : build `linux/amd64` de validation + tests de fumée, jamais poussé.
- Le dépôt est public : les paquets peuvent être rendus publics (Package settings → visibility), ils sont alors gratuits et téléchargeables sans jeton.
- Exemple de déploiement : [`compose.prod.yaml`](compose.prod.yaml) (base, API, worker, front, labels Traefik en commentaire).

## Gitflow

- `main` : production (images `latest` et tags `vX.Y.Z`) ; `develop` : intégration.
- `feature/*` : pull request vers `develop`, qui complète la section `[Non publié]` de [CHANGELOG.md](CHANGELOG.md), publiée sur `/changelog` dans la documentation.
- `release/*` et `hotfix/*` vers `main` : `[Non publié]` devient `[X.Y.Z] - date`, puis tag `vX.Y.Z`.
