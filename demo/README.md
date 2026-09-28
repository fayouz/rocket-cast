# Environnement de démo

## Dans GitHub Codespaces (rien à installer)

1. Sur GitHub, ouvre le dépôt, choisis la branche qui contient la démo, puis **Code → Codespaces → Create codespace on …**.
2. Attends la fin de la commande de démarrage dans le terminal (5 à 10 minutes au premier lancement, le temps de construire les images). Elle affiche les URLs de la démo.
3. Dans l'onglet **Ports**, ouvre « Rocket Cast » (3600) ou « Documentation et changelog » (3601).

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
| http://localhost:3600 | Rocket Cast |
| http://localhost:3601 | Documentation, et le changelog sur `/changelog` |
| http://localhost:8600/api/docs | Documentation de l'API |

Les trois sources de la démo (Rocket PMS, Rocket Place, Web JSON) sont servies par l'API elle-même (`/demo/…`, uniquement avec `DEMO_MODE=1`) : aucun accès au réseau n'est nécessaire, sauf pour la météo et l'image, lues par le navigateur.

## Scénarios

1. **L'écran.** Ouvre http://localhost:3600/s/demo-screen-rocket-cast-0000000000000000000 : accueil au prénom de Camille, horloge, météo, Wi-Fi avec QR code, prochaine arrivée, départ, capteurs, infos du bureau, texte, QR code, image.
2. **Hors ligne.** `docker compose -f compose.yaml -f compose.demo.yaml stop api` : l'écran continue avec le dernier contenu et affiche « Hors ligne » ; `start api` et il reprend.
3. **Éditeur.** Connecte-toi (`alice@example.org` / `demo-alice-password`), *Playlists → Accueil voyageurs (démo)* : sélectionne un panneau, modifie-le, vois l'aperçu, enregistre ; l'écran suit dans la minute.
4. **Appairage.** Ouvre http://localhost:3600/pair?new dans un autre onglet, puis *Écrans → Appairer un écran* avec le code affiché.
5. **Sources.** Avec `admin@example.org` / `demo-admin-password`, *Sources* : *Lire maintenant*, dernières données.
