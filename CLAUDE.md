# Rocket Cast

Brique du Middleware Rocket. Socle commun : [rocket-core](https://github.com/fayouz/rocket-core) (bundle Symfony `rocket/core-bundle` + layer Nuxt `@rocket/core`), à lire avant de modifier les comptes, le SSO, les applications, le tableau de bord ou la mise en page : ce code n'est pas ici.

## Repères
- `app_id` `cast`, jetons d'application `rct_…` (`rca_` est pris par Rocket Cloud), ports front 3600 · api 8600 · docs 3601 ; base locale `rocket-cast-db` sur 127.0.0.1:55438.
- Domaine : affichage dynamique. `src/Cast` (panneaux `Panels`, programmation `Schedule`, payload du kiosque `KioskPayload`, limite publique), `src/Source` (intégrations `SourceTypeInterface` taggées `cast.source_type` : `rocket_pms`, `rocket_place`, `web_json` ; cache et pannes dans `SourceFetcher` ; vues `SourceViews`), `src/Secret` (`SecretStoreInterface`, libsodium avec `ROCKET_SECRETS_KEY` : pas d'identifiant de source dans `.env`).
- Entités : `Screen` (empreinte du jeton seulement), `Playlist` (panneaux en JSON), `Source` (config + secrets scellés + dernier payload), `PairingRequest`.
- Public sans compte : `/api/public/**` (kiosque, appairage) et `/demo/**` (fausses sources, `DEMO_MODE=1` ; hors `/api` pour que le Bearer de la source Place ne rencontre pas le pare-feu). Front : `publicPaths` `/s/`, `/pair`, pages `ssr: false`.
- Stack : Symfony 8.1 + API Platform + Doctrine/PostgreSQL + LexikJWT + Messenger/Scheduler (`backend/`), Nuxt 4 + Nuxt UI 4 qui étend le layer (`frontend/`), Nuxt Content (`docs/`), Docker Compose (`compose.yaml` + `compose.demo.yaml`).
- Points d'extension du socle utilisés : `DashboardSectionInterface` (`CastSection`), `ServiceProbeInterface` (`SourcesProbe`), `DemoSeederInterface` (`CastDemoSeeder`).
- Contrat de la source Rocket PMS : `docs/content/4.api/3.rocket-pms.md` (lit `GET /api/public/tv/{token}` de Rocket PMS ; route dédiée prévue plus tard).

## Vérifier avant de pousser
```bash
cd backend && php bin/console lint:container && php bin/console doctrine:schema:validate && php bin/phpunit
cd frontend && npm run lint && npm run typecheck
cd docs && npm run lint && npm run typecheck && npm run generate   # si docs/ a changé
```
CI : `.github/workflows/ci.yml` appelle les workflows réutilisables de rocket-core (`@vX.Y.Z`) ; scénarios de démo dans `.github/demo-scenarios.sh`.

## Pièges connus
- Tests sans réseau : `tests/Support/HttpMock.php` (le premier préfixe d'URL enregistré qui correspond gagne : réenregistrer la même clé pour changer la réponse). Horloge : `ClockSensitiveTrait::mockTime()`.
- `php -S` local : `PHP_CLI_SERVER_WORKERS=4`, sinon les sources de démo (servies par l'API elle-même) bloquent.
- API Platform répond en JSON-LD par défaut : envoyer `Accept: application/json` (les routes Cast sont des contrôleurs JSON simples).
- Base de test partagée entre dépôts en local (`app_test`) : la recréer et migrer si des colonnes manquent.
- rocket-core suit semver (`^0.x`) ; Renovate ouvre les mises à jour. Ne pas copier une page du layer pour l'étendre : utiliser `rocket.extensions`.
