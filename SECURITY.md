# Security policy / Politique de sécurité

## Supported versions / Versions maintenues

| Version | Supported / Maintenue |
|---|---|
| 1.x (latest release) | ✅ |
| < 1.0 | ❌ |

## Reporting a vulnerability

Please **do not open a public issue** for a security problem. Use GitHub's
private reporting instead: **Security** tab > **Report a vulnerability**.

Include the GLPI and plugin versions, the steps to reproduce and the expected
impact. This is a project maintained on a best-effort basis: you will get an
answer as soon as possible, without a guaranteed delay.

The main risk the plugin guards against is a link to an arbitrary network
share, which could leak the user's Windows (NetNTLM) credentials. Any way to
get a path outside the configured allowed roots saved or turned into a link
is in scope.

## Signaler une vulnérabilité

Merci de **ne pas ouvrir d'issue publique** pour un problème de sécurité.
Utilisez le signalement privé de GitHub : onglet **Security** >
**Report a vulnerability**.

Indiquez les versions de GLPI et du plugin, les étapes pour reproduire et
l'impact attendu. Le projet est maintenu au mieux : vous aurez une réponse
dès que possible, sans délai garanti.

Le principal risque contre lequel le plugin protège est un lien vers un
partage réseau arbitraire, qui pourrait faire fuiter les identifiants Windows
(NetNTLM) de l'utilisateur. Tout moyen de faire enregistrer ou transformer en
lien un chemin situé hors des racines autorisées entre dans le périmètre.
