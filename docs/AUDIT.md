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

> Note : les 3 agents de revue (php-reviewer, symfony-reviewer, security-auditor) ont été lancés en parallèle. Les scores ci-dessous sont établis par l'orchestrateur sur la base de la lecture directe du code source (50 fichiers PHP `src/`), les rapports d'agents n'ayant pas pu être collectés dans le délai imparti.

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **8/10** | `strict_types`, classes `final`, propriétés `readonly`, injection stricte, value objects (`CompletionResult`, `ReorderResult`). Constructor à 11 params dans `DraftOrderCheckout` et méthode `complete()` longue (~85 l.) sont les seuls axes d'amélioration. |
| Intégration Symfony / Sylius | **7/10** | Abstraction `StateMachineInterface` correctement utilisée, décorateur `ExpiredCartsRemover` propre, événements avec fallback catch+log. Point critique : `DraftOrderStepsController` à 13 dépendances + logique adresses/frais à extraire. |
| Sécurité (OWASP 2025) | **7/10** | CSRF validé sur toutes les actions mutantes, `OrderAdjustmentVoter` pour les ajustements sensibles, `OrderRowLock` en transaction pour la concurrence. Risque résiduel : aucun guard d'ownership admin sur `getDraft()` — un admin quelconque peut accéder à n'importe quel draft. |

### Points forts

- `declare(strict_types=1)` + classes `final` sur la totalité de `src/` (50 fichiers).
- `DraftOrderCheckout::complete()` : transaction explicite avec row-lock, rollback sur `OptimisticLockException`, e-mails et événements isolés **hors transaction** avec catch+log (l'order est placé quoi qu'il arrive).
- `OrderAdjustmentVoter` : RBAC config-driven sur les deux attributs sensibles (`PRICE_OVERRIDE`, `MANUAL_DISCOUNT`), séparation nette du voter.
- `DraftControllerTrait::assertCsrfToken()` : CSRF systématiquement vérifié sur les actions POST (delete, reorder).
- `ExpiredCartsRemover` : décorateur propre qui skip les drafts — le core ne les purge plus accidentellement.
- Aucune manipulation de totaux ou d'états en dur : tout passe par `StateMachine` et `OrderProcessor`.
- PHPStan level max + ECS ✅ : aucune erreur de type ni de style.

## 3. Recommandations priorisées

### 🟠 Majeur

1. **Absence de guard d'ownership sur les drafts** (`src/Controller/Admin/DraftControllerTrait.php:34`). La méthode `getDraft(int $orderId)` retourne n'importe quel draft par son `orderId` sans vérifier que l'administrateur connecté en est l'auteur. Un admin peut donc accéder / modifier / supprimer le draft d'un collègue. → Ajouter une vérification `$draft->getCreatedBy() === $this->getUser()` ou une permission Symfony, ou documenter explicitement le choix de partage total entre admins. (OWASP A01:2025)

2. **`DraftOrderStepsController` trop couplé** (`src/Controller/Admin/DraftOrderStepsController.php:61-75`). Le controller concentre 13 dépendances et embarque la logique de calcul d'adresses et de frais (l.497-618). → Extraire un `DraftAddressHandler` et un `DraftShippingPaymentHandler` pour respecter SRP et abaisser la complexité.

3. **Appliquer les 26 suggestions Rector** (`ReadOnlyClassRector` principalement). Les classes candidates (`CompletionResult`, `ReorderResult`, value objects, etc.) peuvent être rendues `readonly`, renforçant l'immuabilité sans risque de régression (PHPStan + tests à 0 erreur). → `make rector-fix` puis re-passer ECS/PHPStan/tests.

### 🟡 Mineur

- **Exception avalée sans log** (`src/Controller/Admin/DraftOrderStepsController.php:615`). → Ajouter un `$this->logger->warning(...)`.
- **Littéraux `'cart'` non typés** (`src/Controller/Admin/DraftOrderStepsController.php:273,334`). → Remplacer par la constante `OrderInterface::STATE_CART`.
- **COALESCE non sargable** (`src/Repository/AdminOrderCreationRepository.php:59,77`). → Revoir le critère de requête pour permettre l'utilisation des index.
- **`DraftOrderCheckout` : constructeur à 11 paramètres** (`src/Checkout/DraftOrderCheckout.php:33-46`). Indicateur d'une classe qui cumule placement, e-mails et dispatch. → Extraire un `OrderCompletionNotifier` regroupant `orderEmailManager`, `paymentLinkEmailManager` et `eventDispatcher`.
- **`DraftOrderCheckout::complete()` : 85 lignes** — dépasse la limite KISS de 20 lignes. → Extraire `commitDraft()` (transaction) et `notifyAfterCompletion()` (e-mails + événement).
- **`@property` docblock dans `DraftControllerTrait`** (`src/Controller/Admin/DraftControllerTrait.php:14`) — typage faible, contournement PHP au lieu d'une injection de dépendance propre. → Passer `$creationRepository` en propriété injectée ou en paramètre de méthode.
- **Paquets abandonnés transitifs** : `behat/transliterator` → pas de remplacement connu (dépend de `sylius/sylius`) ; `php-http/message-factory` → peut migrer vers `psr/http-factory` à l'occasion d'une montée de Sylius.

### ℹ️ Pour information (choix de conception assumés)

- Le `DraftOrderFactory` positionne `createdByGuest = true` délibérément pour que la session shop n'hérite jamais d'un draft admin — comportement correct et documenté.
- Seul le test-applicatif (`tests/TestApplication`) est exclu de Rector — tous les autres tests sont analysés.
- La validation des commandes réutilise les groupes core `sylius` + `sylius_checkout_complete` : cohérence garantie avec l'évolution de Sylius.

## 4. Verdict

Plugin **mature et bien discipliné** : outillage au vert (ECS, PHPStan level max, 67 tests unitaires à 1739 assertions), typage strict, gestion de la concurrence en transaction, CSRF systématique, voter RBAC. Le seul risque à traiter avant une release production est l'**absence de guard d'ownership** sur les drafts admin (OWASP A01) — risque acceptable en contexte mono-équipe mais à documenter explicitement. Les autres axes (extracton notifier, Rector readonly) sont des améliorations sans urgence.
