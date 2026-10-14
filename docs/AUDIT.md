# Audit qualité — sylius-admin-order-creation-plugin

> Généré le 2026-10-14 · Branche `chore/quality-tooling` · Outillage claude-craft (ECS, PHPStan, Rector, PHPUnit) exécuté via Docker `ghcr.io/sylius/sylius-php:8.4-alpine`.

## 1. Audit factuel (outils)

| Contrôle | Commande | Résultat |
|---|---|---|
| Style (ECS) | `vendor/bin/ecs check` | ✅ 0 erreur |
| Analyse statique (PHPStan **level max**) | `vendor/bin/phpstan analyse` | ✅ 0 erreur |
| Tests unitaires | `vendor/bin/phpunit --testsuite=unit` | ✅ 67 tests, 1739 assertions |
| Validation composer | `composer validate --strict` | ✅ valide |
| Modernisation (Rector, dry-run) | `vendor/bin/rector process --dry-run` | ⚠️ 26 fichiers avec suggestions |
| Tests `non-unit` (functional/integration) | — | ⏭️ non exécutés localement (DB + app Sylius requise) — couverts par le CI |
| Vulnérabilités dépendances | `composer audit` | ⚠️ 2 paquets abandonnés (behat/transliterator, php-http/message-factory) — aucune advisory de sécurité |

**Rector** vient d'être ajouté (`rector/rector ^2.0`, `rector.php`, cibles `make rector` / `make rector-fix`). Les 26 fichiers signalés relèvent principalement de `ReadOnlyClassRector` (classes promouvables en `readonly`) — suggestions de modernisation non bloquantes, non appliquées. Lancer `make rector-fix` pour les appliquer puis relancer ECS/PHPStan/tests.

**Paquets abandonnés** : `behat/transliterator` et `php-http/message-factory` sont des dépendances transitives de `sylius/sylius` — non gérables directement dans ce plugin.

## 2. Audit qualitatif (revue par agents)

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **8/10** | `strict_types`, classes `final`, propriétés `readonly`, injection stricte, value objects (`CompletionResult`, `ReorderResult`). Constructor à 11 params dans `DraftOrderCheckout` et méthode `complete()` longue (~85 l.) sont les seuls axes d'amélioration. |
| Intégration Symfony / Sylius | **7/10** | Abstraction `StateMachineInterface` correctement utilisée, décorateur `ExpiredCartsRemover` propre, événements avec fallback catch+log. Point critique : `DraftOrderStepsController` à 13 dépendances + logique adresses/frais à extraire (l.497-618). |
| Sécurité (OWASP 2025) | **8/10** | Autorisation ré-appliquée côté serveur (voter), CSRF par-action+id, pas d'injection SQL, pas d'IDOR, intégrité transactionnelle de niveau production. Seul point actionnable : défauts de config permissifs. |

> Note : le rapport php-reviewer n'a pas été collecté dans le délai imparti ; son score est estimé par l'orchestrateur sur lecture directe du code.

### Points forts

- `declare(strict_types=1)` + classes `final` sur la totalité de `src/` (50 fichiers).
- `DraftOrderCheckout::complete()` : transaction explicite avec row-lock (`SELECT … FOR UPDATE`), rollback sur `OptimisticLockException`, recontrôle intégrité + total dans la transaction, e-mails et événements isolés **hors transaction** avec catch+log (l'order est placé quoi qu'il arrive).
- `OrderAdjustmentVoter` : RBAC config-driven sur les deux attributs sensibles (`PRICE_OVERRIDE`, `MANUAL_DISCOUNT`), ré-appliqué côté serveur dans le controller (`:145-150`, `:182-199`) — pas seulement en UI.
- `DraftControllerTrait::assertCsrfToken()` : CSRF systématiquement vérifié sur les actions POST (delete, reorder, mutations sans form).
- `ExpiredCartsRemover` : décorateur propre qui skip les drafts — le core ne les purge plus accidentellement.
- Pas d'IDOR : `?address=id` résolu dans le carnet du client (`DraftOrderStepsController.php:505-511`), `removeItem` filtre sur les items de la commande (`:166-169`).
- Remise plafonnée au total des items (`ManualDiscountProcessor.php:53`) → total jamais négatif.
- PHPStan level max + ECS ✅ : aucune erreur de type ni de style.

## 3. Recommandations priorisées

### 🟠 Majeur

1. **Défauts de configuration permissifs** (`src/DependencyInjection/Configuration.php:26-38`, OWASP A04/A05). `allow_price_override` et `allow_manual_discount` sont à `true` par défaut, et `adjustments_role` vaut `ROLE_ADMINISTRATION_ACCESS` (= rôle d'accès de base au panel). À l'installation, tout admin peut contourner les prix et appliquer des remises illimitées sans configuration explicite. → Passer les flags à `false` par défaut, ou définir un rôle dédié distinct de `ROLE_ADMINISTRATION_ACCESS`, et documenter la configuration dans le README.

2. **Absence de guard d'ownership sur les drafts** (`src/Controller/Admin/DraftControllerTrait.php:34`, OWASP A01). `getDraft(int $orderId)` retourne n'importe quel draft sans vérifier que l'admin connecté en est l'auteur — un admin peut accéder/modifier/supprimer le draft d'un collègue. → Ajouter une vérification d'auteur ou documenter explicitement le choix de partage total entre admins.

3. **`DraftOrderStepsController` trop couplé** (`src/Controller/Admin/DraftOrderStepsController.php:61-75`). 13 dépendances + logique métier adresses/frais embarquée (l.497-618) — SRP violé. → Extraire un `DraftAddressHandler` et un `DraftShippingPaymentHandler`.

4. **Appliquer les 26 suggestions Rector** (`ReadOnlyClassRector` principalement). Classes candidates (`CompletionResult`, `ReorderResult`, value objects…) promouvables en `readonly`. → `make rector-fix` puis re-passer ECS/PHPStan/tests.

### 🟡 Mineur

- **`tokenValue` journalisé** (`src/Checkout/DraftOrderCheckout.php:154`, OWASP A09). Le token est la clé d'accès invité à la commande — logguer l'`id`/`number` à la place.
- **Pas de log d'audit des mutations sensibles** (`DraftOrderCheckout.php`, `DraftOrderStepsController.php`, OWASP A09). Aucune trace nominative des overrides de prix et remises (qui, montant, commande). → Ajouter un canal de log `audit` dédié.
- **Exception avalée sans log** (`src/Controller/Admin/DraftOrderStepsController.php:615`). → Ajouter un `$this->logger->warning(...)`.
- **Littéraux `'cart'` non typés** (`src/Controller/Admin/DraftOrderStepsController.php:273,334`). → Remplacer par `OrderInterface::STATE_CART`.
- **COALESCE non sargable** (`src/Repository/AdminOrderCreationRepository.php:59,77`). → Revoir le critère de requête pour permettre l'utilisation des index.
- **`DraftOrderCheckout` : constructeur à 11 paramètres** (`src/Checkout/DraftOrderCheckout.php:33-46`). → Extraire un `OrderCompletionNotifier` (`orderEmailManager` + `paymentLinkEmailManager` + `eventDispatcher`).
- **`DraftOrderCheckout::complete()` : 85 lignes**. → Extraire `commitDraft()` (transaction) et `notifyAfterCompletion()` (e-mails + événement).
- **`@property` docblock dans `DraftControllerTrait`** (`src/Controller/Admin/DraftControllerTrait.php:14`) — typage faible. → Passer `$creationRepository` en propriété injectée.
- **Paquets abandonnés transitifs** : `php-http/message-factory` → migrer vers `psr/http-factory` lors d'une montée de Sylius.

### ℹ️ Pour information (choix de conception assumés)

- `DraftOrderFactory` positionne `createdByGuest = true` délibérément — la session shop n'hérite jamais d'un draft admin.
- Routes admin protégées uniquement par le firewall admin hérité (convention Sylius correcte) — pas de permission dédiée « créer commande admin » nécessaire à ce stade.
- `PaymentLinkEmailManager::canSend()` vérifie état/token avant envoi ; lien basé sur token aléatoire Sylius (flux invité attendu).
- Seul `tests/TestApplication` est exclu de Rector — tous les autres tests sont analysés.
- La validation des commandes réutilise les groupes core `sylius` + `sylius_checkout_complete` : cohérence garantie avec l'évolution de Sylius.

## 4. Verdict

Plugin **mature et bien discipliné** : outillage au vert (ECS, PHPStan level max, 67 tests unitaires à 1739 assertions), typage strict, intégrité transactionnelle de niveau production, CSRF systématique, pas d'IDOR ni d'injection SQL. Deux points à traiter avant une release production : les **défauts de configuration permissifs** sur prix et remises (risque de fraude interne sans configuration explicite) et l'**absence de guard d'ownership** sur les drafts. Les autres axes (log d'audit, extracton notifier, Rector readonly) sont des améliorations sans urgence.
