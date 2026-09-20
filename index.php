<?php
// ============================================================
// MYBOUTIK - Backend complet PostgreSQL
// Plateforme multi-boutiques : creation de boutique, catalogue,
// vitrine publique, commandes paiement a la livraison (COD),
// livraisons, clients, finances, analytique, marketing.
// Meme style que ROM_MONEY/APPLICATION/index.php (routeur module/action,
// helpers ok()/fail()/db()/q(), JWT maison, aucune valeur par defaut
// codee en dur pour les secrets).
// ============================================================

define('DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
define('DB_NAME',     getenv('DB_NAME')     ?: 'myboutik_db');
define('DB_USER',     getenv('DB_USER')     ?: 'postgres');
define('DB_PASS',     getenv('DB_PASS')     ?: '');
define('DB_PORT',     getenv('DB_PORT')     ?: '5432');
// Neon (et la plupart des Postgres serverless) exigent une connexion
// chiffree et refusent toute tentative en clair. Render Postgres et un
// Postgres local l'acceptent aussi sans probleme, donc ce reglage est sans
// danger dans tous les cas - mais il reste desactive par defaut (chaine
// vide) pour ne rien changer au comportement existant tant qu'il n'est pas
// explicitement demande.
define('DB_SSLMODE',  getenv('DB_SSLMODE')  ?: '');
define('JWT_SECRET',  getenv('JWT_SECRET')  ?: null);
// Cle dediee pour route_install() (creation/mise a jour des tables), separee
// de JWT_SECRET par souci de coherence avec ROM_MONEY. Si INSTALL_KEY n'est
// pas configuree, on retombe sur JWT_SECRET.
define('INSTALL_KEY', getenv('INSTALL_KEY') ?: JWT_SECRET);
// Cle pour declencher les taches planifiees (relances paniers abandonnes,
// alertes de stock) - aucun worker en arriere-plan sur cet hebergement, donc
// un service de cron externe (cron-job.org, etc.) appelle /cron?key=...&
// action=... a intervalle regulier. Repli sur INSTALL_KEY, meme logique.
define('CRON_KEY', getenv('CRON_KEY') ?: INSTALL_KEY);
// Aucune valeur de repli codee en dur pour ce secret : un secret visible
// dans le code source n'est plus un secret. Si JWT_SECRET n'est pas
// configuree sur l'hebergeur, l'app s'arrete plutot que de tourner avec
// un secret compromis/devinable.
if (!JWT_SECRET) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>'Configuration serveur incomplete: JWT_SECRET non defini.'], JSON_UNESCAPED_UNICODE);
    exit;
}
define('JWT_EXPIRY', 30 * 86400); // 30 jours (etait 12h) - voir token_version pour la revocation a distance
// IMPORTANT: par defaut (variable absente) on retombe sur 'production'
// (sur, ferme), jamais 'development' (permissif, ouvre le CORS a tout le
// web et affiche les erreurs BDD brutes). 'development' ne doit s'activer
// que par un choix EXPLICITE.
define('APP_ENV',   getenv('APP_ENV')   ?: 'production');
define('APP_DEBUG', APP_ENV === 'development');
// Mot de passe du panneau admin (validation manuelle des demandes
// d'abonnement - voir route_admin()). Optionnel : contrairement a
// JWT_SECRET, son absence ne bloque pas le demarrage de l'app, seules les
// routes /admin repondent "non configure" tant qu'il n'est pas defini.
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: null);
// Adresse recevant les alertes qualite automatiques (nouvelle reclamation,
// boutique qui cumule trop de reclamations en attente - voir
// notify_admin_new_dispute()). Optionnelle : si absente, ces emails sont
// simplement sautes (l'operateur garde de toute facon la vue d'ensemble
// dans le panneau admin), rien ne casse.
define('ADMIN_NOTIFY_EMAIL', getenv('ADMIN_NOTIFY_EMAIL') ?: null);

// Envoi d'email transactionnel (Brevo, https://app.brevo.com/settings/keys/api)
// - optionnel : en son absence, send_email() se contente de journaliser
// comme avant (voir plus bas), rien ne casse.
define('BREVO_API_KEY',     getenv('BREVO_API_KEY')     ?: null);
define('BREVO_SENDER_EMAIL',getenv('BREVO_SENDER_EMAIL')?: null);
define('BREVO_SENDER_NAME', getenv('BREVO_SENDER_NAME') ?: 'MYBOUTIK');
// Origine du frontend statique (GitHub Pages) - utilisee pour construire les
// liens de redirection dans les pages d'apercu (voir route_preview()) et
// pour le CTA de l'annuaire. Configurable par env var pour ne pas casser au
// prochain demenagement d'hebergement (deja arrive une fois cette session).
define('FRONTEND_BASE_URL', rtrim(getenv('FRONTEND_BASE_URL') ?: 'https://lumen-devworks.github.io/myboutik', '/'));
// Numero unique reutilise partout (instructions de paiement, bouton
// WhatsApp "J'ai paye", copie en un clic cote tableau de bord) - un seul
// endroit a modifier si ce numero change un jour.
define('PAYMENT_PHONE_DISPLAY', '+225 07 78 79 83 19');
// Compte dedie aux boutiques de demonstration (admin_seed_demo_data()) -
// jamais un vrai marchand, sert uniquement a regrouper ces boutiques pour
// pouvoir tout supprimer d'un coup (admin_delete_demo_data()).
define('DEMO_SEED_EMAIL', 'demo-seed@myboutik.internal');
// CORS restreint : seules les origines listees ici peuvent appeler l'API
// directement depuis un navigateur. A completer avec le(s) domaine(s) ou
// sont hebergees index.html / dashboard / store une fois deployees.
$ALLOWED_ORIGINS = [
    'https://lumen-devworks.github.io',
    'https://myboutik-ci.netlify.app',
];
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($requestOrigin, $ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $requestOrigin");
} elseif (APP_ENV === 'development') {
    header("Access-Control-Allow-Origin: *"); // confort en developpement local uniquement
}
header("Vary: Origin");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Lang");
header("Content-Type: application/json; charset=utf-8");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ============================================================
// TRADUCTION DES MESSAGES SERVEUR (FR par defaut, EN a la demande)
// ============================================================
// Le frontend envoie l'entete X-Lang: en quand l'utilisateur a choisi
// l'anglais (voir Access-Control-Allow-Headers ci-dessus). t() traduit les
// messages STATIQUES via une correspondance exacte francais->anglais.
// Les quelques messages construits dynamiquement (ex: erreurs SQL brutes,
// limites avec un nombre insere en cours de phrase) ne peuvent pas etre
// traduits par une simple table de correspondance puisque le texte final
// varie a chaque appel - ils restent en francais meme en mode EN.
function current_lang() {
    $l = strtolower($_SERVER['HTTP_X_LANG'] ?? '');
    return $l === 'en' ? 'en' : 'fr';
}
function t($msg) {
    if (current_lang() !== 'en') return $msg;
    return EN_DICT[$msg] ?? $msg;
}
const EN_DICT = [
    '5 images maximum par produit' => '5 images maximum per product',
    'Action inconnue' => 'Unknown action',
    'Action non autorisee pour votre role' => 'Action not allowed for your role',
    'Action reservee au proprietaire ou a un administrateur de la boutique' => 'Action reserved for the shop owner or an administrator',
    'Adresse email invalide' => 'Invalid email address',
    'Avis envoye. Merci pour votre retour !' => 'Feedback sent. Thank you for your input!',
    'Marque comme repondu' => 'Marked as replied',
    'Ajoutez au moins un produit' => 'Add at least one product',
    'Aucun lien de feuille configure' => 'No sheet link configured',
    'Aucune commande trouvee avec ces informations' => 'No order found with this information',
    'Aucune information a capturer' => 'No information to capture',
    'Avis introuvable' => 'Review not found',
    'Avis mis a jour' => 'Review updated',
    'Avis supprime' => 'Review deleted',
    'Boutique creee' => 'Shop created',
    'Boutique introuvable' => 'Shop not found',
    'Boutique suspendue' => 'Shop suspended',
    'Boutique reactivee' => 'Shop reactivated',
    'La ville de la boutique est requise' => 'The shop city is required',
    'Le pays de la boutique est requis' => 'The shop country is required',
    'Boutique mise a jour' => 'Shop updated',
    'Categorie creee' => 'Category created',
    'Categorie introuvable' => 'Category not found',
    'Categorie mise a jour' => 'Category updated',
    'Categorie supprimee' => 'Category deleted',
    'Ce code existe deja pour cette boutique' => 'This code already exists for this shop',
    'Ce membre est deja invite sur cette boutique' => 'This member is already invited to this shop',
    'Cette invitation est destinee a une autre adresse email' => 'This invitation is for a different email address',
    'Cette livraison ne vous est pas assignee' => 'This delivery is not assigned to you',
    'Choisissez deux comptes differents' => 'Choose two different accounts',
    'Client introuvable' => 'Customer not found',
    'Code promo cree' => 'Promo code created',
    'Code promo introuvable' => 'Promo code not found',
    'Code promo invalide, expire, ou reserve a un autre client' => 'Promo code invalid, expired, or reserved for another customer',
    'Code promo mis a jour' => 'Promo code updated',
    'Code promo supprime' => 'Promo code deleted',
    'Code supprime' => 'Code deleted',
    'Commande assignee' => 'Order assigned',
    'Commande creee' => 'Order created',
    'Commande enregistree' => 'Order recorded',
    'Commande fournisseur creee' => 'Supplier order created',
    'Commande fournisseur introuvable' => 'Supplier order not found',
    'Commande introuvable' => 'Order not found',
    'Decrivez le probleme rencontre' => 'Describe the problem you encountered',
    'Une reclamation est deja en cours de traitement pour cette commande' => 'A complaint is already being processed for this order',
    'La reclamation n\'est disponible que pour une commande deja livree' => 'Complaints are only available for an already delivered order',
    'Votre reclamation a ete envoyee au marchand.' => 'Your complaint has been sent to the merchant.',
    'Aucune reclamation ouverte pour cette commande' => 'No open complaint for this order',
    'Ecrivez une reponse' => 'Write a response',
    'Devise invalide' => 'Invalid currency',
    'Taux de change invalide' => 'Invalid exchange rate',
    'Taux enregistres' => 'Rates saved',
    'Table des taux absente : relancez /install puis reessayez' => 'Rates table missing: run /install again then retry',
    'Ecrivez le message de l\'annonce' => 'Write the announcement message',
    'Message trop long (1000 caracteres maximum)' => 'Message too long (1000 characters maximum)',
    'Destinataires invalides' => 'Invalid recipients',
    'Date de fin invalide' => 'Invalid end date',
    'La date de fin doit etre dans le futur' => 'The end date must not be in the past',
    'Annonce publiee' => 'Announcement published',
    'Annonce mise a jour' => 'Announcement updated',
    'Annonce supprimee' => 'Announcement deleted',
    'Annonce introuvable' => 'Announcement not found',
    'Table des annonces absente : relancez /install puis reessayez' => 'Announcements table missing: run /install again then retry',
    'Ecrivez un message d\'avertissement' => 'Write a warning message',
    'Avertissement envoye' => 'Warning sent',
    'Reponse envoyee' => 'Response sent',
    'Compte cree' => 'Account created',
    'Compte cree. Verifiez votre email pour activer votre compte.' => 'Account created. Check your email to activate your account.',
    'Compte introuvable' => 'Account not found',
    'Compte suspendu ou bloque' => 'Account suspended or blocked',
    'Connecte' => 'Logged in',
    'Contact ajoute' => 'Contact added',
    'Contact introuvable' => 'Contact not found',
    'Contact mis a jour' => 'Contact updated',
    'Contact supprime' => 'Contact deleted',
    'Demande de retrait envoyee. Vous serez paye(e) apres verification.' => 'Withdrawal request sent. You will be paid after verification.',
    'Demande deja en attente de verification' => 'Request already pending verification',
    'Demande enregistree. Envoyez le montant via Orange Money, Wave ou Djomo au +225 07 78 79 83 19 (MYBOUTIK) - votre plan sera active des verification du paiement par l\'equipe MYBOUTIK (generalement sous 24h).' => 'Request recorded. Send the amount via Orange Money, Wave or Djomo to +225 07 78 79 83 19 (MYBOUTIK) - your plan will be activated once the MYBOUTIK team verifies the payment (usually within 24h).',
    'Demande introuvable' => 'Request not found',
    'Demande rejetee' => 'Request rejected',
    'Depense enregistree' => 'Expense recorded',
    'Depense introuvable' => 'Expense not found',
    'Depense publicitaire enregistree' => 'Ad expense recorded',
    'Email ou mot de passe incorrect' => 'Incorrect email or password',
    'Email verifie. Vous pouvez vous connecter.' => 'Email verified. You can now log in.',
    'Fournisseur ajoute' => 'Supplier added',
    'Fournisseur introuvable' => 'Supplier not found',
    'Fournisseur mis a jour' => 'Supplier updated',
    'Fournisseur supprime' => 'Supplier deleted',
    'Frais enregistre' => 'Fee recorded',
    'Image ajoutee' => 'Image added',
    'Image introuvable' => 'Image not found',
    'Image manquante' => 'Missing image',
    'Image principale mise a jour' => 'Main image updated',
    'Image supprimee' => 'Image deleted',
    'Impossible de recuperer la feuille (verifiez que le lien est bien publie en CSV et accessible publiquement)' => 'Unable to retrieve the sheet (check that the link is published as CSV and publicly accessible)',
    'Inscription confirmee' => 'Subscription confirmed',
    'Installation terminee ! Toutes les tables ont ete creees.' => 'Installation complete! All tables have been created.',
    'Introuvable' => 'Not found',
    'Invitation acceptee' => 'Invitation accepted',
    'Invitation envoyee' => 'Invitation sent',
    'Invitation invalide ou deja utilisee' => 'Invalid or already used invitation',
    'L\'abonnement de cette boutique a expire. Le proprietaire doit se reabonner (menu Abonnement) pour continuer a l\'utiliser.' => 'This shop\'s subscription has expired. The owner must renew (Subscription menu) to keep using it.',
    'La feuille est vide (juste l\'entete ou aucune ligne)' => 'The sheet is empty (only the header or no rows)',
    'La valeur doit etre superieure a 0' => 'The value must be greater than 0',
    'Le code est requis' => 'The code is required',
    'Le message ne peut pas etre vide' => 'The message cannot be empty',
    'Le mot de passe doit contenir au moins 6 caracteres' => 'The password must be at least 6 characters',
    'Le nom de la boutique est requis' => 'The shop name is required',
    'Le nom de la categorie est requis' => 'The category name is required',
    'Le nom du compte est requis' => 'The account name is required',
    'Le nom du contact est requis' => 'The contact name is required',
    'Le nom du fournisseur est requis' => 'The supplier name is required',
    'Le nom du livreur est requis' => 'The delivery person\'s name is required',
    'Le nom du produit est requis' => 'The product name is required',
    'Le nom du role est requis' => 'The role name is required',
    'Le nouveau mot de passe doit contenir au moins 6 caracteres' => 'The new password must be at least 6 characters',
    'Le paiement a la livraison n\'est pas active pour cette boutique' => 'Cash on delivery is not enabled for this shop',
    'Le panier est vide' => 'The cart is empty',
    'Libelle et montant requis' => 'Label and amount required',
    'Lien de verification invalide ou deja utilise' => 'Invalid or already used verification link',
    'Lien de reinitialisation invalide' => 'Invalid reset link',
    'Lien de reinitialisation invalide ou expire' => 'Invalid or expired reset link',
    'Si un compte existe avec cet email, un lien de reinitialisation vient d\'etre envoye.' => 'If an account exists with this email, a reset link has just been sent.',
    'Mot de passe reinitialise. Vous pouvez maintenant vous connecter.' => 'Password reset. You can now log in.',
    'Session invalidee, reconnectez-vous' => 'Session invalidated, please log in again',
    'Deconnecte de tous les autres appareils. Cette session reste active.' => 'Logged out of all other devices. This session remains active.',
    'Livreur ajoute' => 'Delivery person added',
    'Livreur desactive' => 'Delivery person deactivated',
    'Livreur introuvable' => 'Delivery person not found',
    'Livreur mis a jour' => 'Delivery person updated',
    'Marque comme lu' => 'Marked as read',
    'Membre retire' => 'Member removed',
    'Merci pour votre avis !' => 'Thank you for your review!',
    'Message envoye' => 'Message sent',
    'Mis a jour' => 'Updated',
    'Module inconnu' => 'Unknown module',
    'Montant invalide' => 'Invalid amount',
    'Mot de passe incorrect' => 'Incorrect password',
    'Mouvement enregistre' => 'Transaction recorded',
    'Nom et telephone du client requis' => 'Customer name and phone required',
    'Nom et telephone requis' => 'Name and phone required',
    'Non autorise' => 'Not authorized',
    'Note invalide (1 a 5)' => 'Invalid rating (1 to 5)',
    'Numero Mobile Money requis' => 'Mobile Money number required',
    'Un email est requis pour recevoir un produit numerique' => 'An email is required to receive a digital product',
    'Numero de commande et telephone requis' => 'Order number and phone required',
    'Panneau admin non configure (variable ADMIN_PASSWORD absente)' => 'Admin panel not configured (ADMIN_PASSWORD variable missing)',
    'Parametres enregistres' => 'Settings saved',
    'Plan active' => 'Plan activated',
    'Votre mois gratuit Starter est active immediatement !' => 'Your free Starter month is activated immediately!',
    'Plan invalide' => 'Invalid plan',
    'Produit cree' => 'Product created',
    'Produit introuvable' => 'Product not found',
    'Produit mis a jour' => 'Product updated',
    'Produit supprime' => 'Product deleted',
    'Profil mis a jour' => 'Profile updated',
    'Promotion creee' => 'Promotion created',
    'Promotion introuvable' => 'Promotion not found',
    'Promotion mise a jour' => 'Promotion updated',
    'Promotion supprimee' => 'Promotion deleted',
    'Reglages enregistres' => 'Settings saved',
    'Retrait introuvable' => 'Withdrawal not found',
    'Retrait marque paye' => 'Withdrawal marked as paid',
    'Role cree' => 'Role created',
    'Role invalide' => 'Invalid role',
    'Selectionnez au moins un produit' => 'Select at least one product',
    'Si un compte existe avec cet email, un nouveau lien vient d\'etre envoye.' => 'If an account exists with this email, a new link has just been sent.',
    'Statut invalide' => 'Invalid status',
    'Statut mis a jour' => 'Status updated',
    'Stock ajuste' => 'Stock adjusted',
    'Token invalide ou expire' => 'Invalid or expired token',
    'Token manquant' => 'Missing token',
    'Transfert effectue' => 'Transfer completed',
    'Transfert impossible' => 'Transfer not possible',
    'Trop de requetes depuis cette adresse. Reessayez dans quelques instants.' => 'Too many requests from this address. Please try again shortly.',
    'Type invalide' => 'Invalid type',
    'Un compte existe deja avec cet email' => 'An account already exists with this email',
    'Un pourcentage ne peut pas depasser 100' => 'A percentage cannot exceed 100',
    'Veuillez verifier votre email avant de vous connecter' => 'Please verify your email before logging in',
    'Votre nom est requis' => 'Your name is required',
    'Votre telephone est requis' => 'Your phone number is required',
    'Votre role n\'a pas acces a cette section' => 'Your role does not have access to this section',
    'boutique_id manquant' => 'boutique_id missing',
    'OK' => 'OK',
];

// ============================================================
// HELPERS GENERIQUES
// ============================================================

function ok($data = null, $msg = 'OK', $code = 200) {
    http_response_code($code);
    echo json_encode(['success'=>true,'message'=>t($msg),'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success'=>false,'message'=>t($msg)], JSON_UNESCAPED_UNICODE);
    exit;
}
function log_and_fail($e, $userMsg, $code = 500) {
    error_log('[MYBOUTIK] '.$userMsg.' :: '.$e->getMessage());
    fail(APP_DEBUG ? $e->getMessage() : $userMsg, $code);
}
function body() {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}
// Lit un parametre depuis le corps JSON en priorite, sinon la query string.
function bg($key, $default=null) {
    $b = body();
    if (array_key_exists($key, $b)) return $b[$key];
    return $_GET[$key] ?? $default;
}
function b64e($d) { return rtrim(strtr(base64_encode($d),'+/','-_'),'='); }
function b64d($d) { return base64_decode(strtr($d,'-_','+/').str_repeat('=',(3+strlen($d))%4)); }
function jwt_make($payload) {
    $h = b64e(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload['iat'] = time(); $payload['exp'] = time()+JWT_EXPIRY;
    $b = b64e(json_encode($payload));
    return "$h.$b.".b64e(hash_hmac('sha256',"$h.$b",JWT_SECRET,true));
}
function jwt_check($token) {
    $p = explode('.',$token);
    if(count($p)!==3) return null;
    if(!hash_equals(b64e(hash_hmac('sha256',"$p[0].$p[1]",JWT_SECRET,true)),$p[2])) return null;
    $pl = json_decode(b64d($p[1]),true);
    return ($pl && $pl['exp']>time()) ? $pl : null;
}
// Authentification du proprietaire de boutique(s) (table users). Seule
// identite de cette app (pas d'admin, pas de role separe pour l'instant).
function owner_auth() {
    $h = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? (function_exists("getallheaders") ? (getallheaders()["Authorization"] ?? "") : "") ?? "";
    if(!str_starts_with($h,'Bearer ')) fail('Token manquant',401);
    $pl = jwt_check(substr($h,7));
    if(!$pl || ($pl['typ']??'')!=='owner') fail('Token invalide ou expire',401);
    $row = q("SELECT status, token_version FROM users WHERE id=?",[$pl['sub']])->fetch();
    if($row === false) fail('Compte introuvable',401);
    if($row['status'] !== 'active') fail('Compte suspendu ou bloque', 403);
    // La session de 30 jours (voir JWT_EXPIRY) reste valide tout ce temps
    // SAUF si le compte a demande une deconnexion a distance entre-temps
    // (auth_logout_other_devices()) ou reinitialise son mot de passe
    // (auth_reset_password()) - les deux incrementent token_version, ce qui
    // invalide immediatement tout jeton emis avant, sans attendre son
    // expiration naturelle.
    if ((int)($pl['tv'] ?? 0) !== (int)($row['token_version'] ?? 0)) fail('Session invalidee, reconnectez-vous', 401);
    return $pl;
}
function uid() { return bin2hex(random_bytes(8)); }
function order_ref() { return 'CMD-'.strtoupper(date('ymd')).'-'.strtoupper(substr(uniqid(),-6)); }
// Normalise un numero de telephone pour comparaison : ne garde que les
// chiffres et se limite aux 8 derniers, pour que "07 78 79 83 19",
// "0778798319" et "+225 07 78 79 83 19" soient reconnus comme le meme
// numero peu importe le format saisi a la commande vs au suivi.
function phone_key($phone) {
    $digits = preg_replace('/\D/', '', (string)$phone);
    return substr($digits, -8);
}

function slugify($text) {
    $text = trim((string)$text);
    $translit = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$text);
    if ($translit !== false && $translit !== '') $text = $translit;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/','-',$text);
    $text = trim($text,'-');
    if ($text === '') $text = 'boutique';
    return substr($text,0,60);
}
function unique_boutique_slug($base) {
    $slug = $base; $i = 1;
    while (q("SELECT 1 FROM boutiques WHERE slug=?",[$slug])->fetch()) {
        $i++; $slug = $base.'-'.$i;
    }
    return $slug;
}

function db(): PDO {
    static $pdo = null;
    if(!$pdo) {
        try {
            $dsn = "pgsql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME;
            if (DB_SSLMODE !== '') $dsn .= ";sslmode=".DB_SSLMODE;
            $pdo = new PDO(
                $dsn,
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>true]
            );
        } catch(PDOException $e) {
            error_log('[MYBOUTIK] Erreur serveur :: BDD: '.$e->getMessage());
            fail(APP_DEBUG ? 'BDD: '.$e->getMessage() : 'Erreur serveur', 500);
        }
    }
    return $pdo;
}
function q($sql, $params=[]) {
    $s = db()->prepare($sql);
    $s->execute($params);
    return $s;
}

function rate_limit_check($bucket, $maxRequests, $windowSeconds) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (mt_rand(1, 100) === 1) {
            q("DELETE FROM rate_limit_hits WHERE created_at < NOW() - INTERVAL '1 hour'");
        }
        $row = q("SELECT COUNT(*) c FROM rate_limit_hits
                  WHERE bucket=? AND ip_address=?
                  AND created_at > NOW() - (?::text || ' seconds')::interval",
                  [$bucket, $ip, $windowSeconds])->fetch();
        if ($row && (int)$row['c'] >= $maxRequests) {
            fail('Trop de requetes depuis cette adresse. Reessayez dans quelques instants.', 429);
        }
        q("INSERT INTO rate_limit_hits (bucket, ip_address) VALUES (?,?)", [$bucket, $ip]);
    } catch (PDOException $e) { /* table pas encore prete : on laisse passer */ }
}

// $actorUserId absent (commande passee depuis la vitrine publique, par un
// client) -> actor_email reste NULL, affiche comme "Client" dans Historique.
function log_activity($boutiqueId, $message, $actorUserId = null) {
    try {
        $actorEmail = $actorUserId ? (q("SELECT email FROM users WHERE id=?", [$actorUserId])->fetchColumn() ?: null) : null;
        q("INSERT INTO activity_log (boutique_id, message, actor_email) VALUES (?,?,?)", [$boutiqueId, $message, $actorEmail]);
    } catch (PDOException $e) { /* jamais bloquant */ }
}

function period_clause($period, $col='created_at') {
    switch ($period) {
        case '7d':  return "$col >= NOW() - INTERVAL '7 days'";
        case '90d': return "$col >= NOW() - INTERVAL '90 days'";
        case 'all': return "1=1";
        case '30d':
        default:    return "$col >= NOW() - INTERVAL '30 days'";
    }
}

// Statuts consideres comme "argent encaisse" (validee ou livree), par
// opposition a pending (pas encore traitee)/refusee/annulee.
const ENCAISSE_STATUSES = "('processing','shipped','delivered')";

// Devise habituelle par pays (noms francais, comme COUNTRIES_FR cote
// frontend) : la devise d'une boutique se choisit automatiquement d'apres son
// pays. Un pays absent de la table retombe sur XOF (comportement d'origine).
const COUNTRY_CURRENCY = [
    'Afghanistan' => 'AFN',
    'Afrique du Sud' => 'ZAR',
    'Albanie' => 'ALL',
    'Algérie' => 'DZD',
    'Allemagne' => 'EUR',
    'Andorre' => 'EUR',
    'Angola' => 'AOA',
    'Arabie saoudite' => 'SAR',
    'Argentine' => 'ARS',
    'Arménie' => 'AMD',
    'Australie' => 'AUD',
    'Autriche' => 'EUR',
    'Azerbaïdjan' => 'AZN',
    'Bahamas' => 'BSD',
    'Bahreïn' => 'BHD',
    'Bangladesh' => 'BDT',
    'Barbade' => 'BBD',
    'Belgique' => 'EUR',
    'Belize' => 'BZD',
    'Bénin' => 'XOF',
    'Bhoutan' => 'BTN',
    'Biélorussie' => 'BYN',
    'Birmanie' => 'MMK',
    'Bolivie' => 'BOB',
    'Bosnie-Herzégovine' => 'BAM',
    'Botswana' => 'BWP',
    'Brésil' => 'BRL',
    'Brunei' => 'BND',
    'Bulgarie' => 'EUR',
    'Burkina Faso' => 'XOF',
    'Burundi' => 'BIF',
    'Cambodge' => 'KHR',
    'Cameroun' => 'XAF',
    'Canada' => 'CAD',
    'Cap-Vert' => 'CVE',
    'Chili' => 'CLP',
    'Chine' => 'CNY',
    'Chypre' => 'EUR',
    'Colombie' => 'COP',
    'Comores' => 'KMF',
    'Congo-Brazzaville' => 'XAF',
    'Congo-Kinshasa' => 'CDF',
    'Corée du Nord' => 'KPW',
    'Corée du Sud' => 'KRW',
    'Costa Rica' => 'CRC',
    'Côte d\'Ivoire' => 'XOF',
    'Croatie' => 'EUR',
    'Cuba' => 'CUP',
    'Danemark' => 'DKK',
    'Djibouti' => 'DJF',
    'Dominique' => 'XCD',
    'Égypte' => 'EGP',
    'Émirats arabes unis' => 'AED',
    'Équateur' => 'USD',
    'Érythrée' => 'ERN',
    'Espagne' => 'EUR',
    'Estonie' => 'EUR',
    'Eswatini' => 'SZL',
    'États-Unis' => 'USD',
    'Éthiopie' => 'ETB',
    'Fidji' => 'FJD',
    'Finlande' => 'EUR',
    'France' => 'EUR',
    'Gabon' => 'XAF',
    'Gambie' => 'GMD',
    'Géorgie' => 'GEL',
    'Ghana' => 'GHS',
    'Grèce' => 'EUR',
    'Grenade' => 'XCD',
    'Guatemala' => 'GTQ',
    'Guinée' => 'GNF',
    'Guinée-Bissau' => 'XOF',
    'Guinée équatoriale' => 'XAF',
    'Guyana' => 'GYD',
    'Haïti' => 'HTG',
    'Honduras' => 'HNL',
    'Hongrie' => 'HUF',
    'Inde' => 'INR',
    'Indonésie' => 'IDR',
    'Irak' => 'IQD',
    'Iran' => 'IRR',
    'Irlande' => 'EUR',
    'Islande' => 'ISK',
    'Israël' => 'ILS',
    'Italie' => 'EUR',
    'Jamaïque' => 'JMD',
    'Japon' => 'JPY',
    'Jordanie' => 'JOD',
    'Kazakhstan' => 'KZT',
    'Kenya' => 'KES',
    'Kirghizistan' => 'KGS',
    'Kiribati' => 'AUD',
    'Koweït' => 'KWD',
    'Laos' => 'LAK',
    'Lesotho' => 'LSL',
    'Lettonie' => 'EUR',
    'Liban' => 'LBP',
    'Liberia' => 'LRD',
    'Libye' => 'LYD',
    'Liechtenstein' => 'CHF',
    'Lituanie' => 'EUR',
    'Luxembourg' => 'EUR',
    'Macédoine du Nord' => 'MKD',
    'Madagascar' => 'MGA',
    'Malaisie' => 'MYR',
    'Malawi' => 'MWK',
    'Maldives' => 'MVR',
    'Mali' => 'XOF',
    'Malte' => 'EUR',
    'Maroc' => 'MAD',
    'Marshall' => 'USD',
    'Maurice' => 'MUR',
    'Mauritanie' => 'MRU',
    'Mexique' => 'MXN',
    'Micronésie' => 'USD',
    'Moldavie' => 'MDL',
    'Monaco' => 'EUR',
    'Mongolie' => 'MNT',
    'Monténégro' => 'EUR',
    'Mozambique' => 'MZN',
    'Namibie' => 'NAD',
    'Nauru' => 'AUD',
    'Népal' => 'NPR',
    'Nicaragua' => 'NIO',
    'Niger' => 'XOF',
    'Nigeria' => 'NGN',
    'Norvège' => 'NOK',
    'Nouvelle-Zélande' => 'NZD',
    'Oman' => 'OMR',
    'Ouganda' => 'UGX',
    'Ouzbékistan' => 'UZS',
    'Pakistan' => 'PKR',
    'Palaos' => 'USD',
    'Palestine' => 'ILS',
    'Panama' => 'USD',
    'Papouasie-Nouvelle-Guinée' => 'PGK',
    'Paraguay' => 'PYG',
    'Pays-Bas' => 'EUR',
    'Pérou' => 'PEN',
    'Philippines' => 'PHP',
    'Pologne' => 'PLN',
    'Portugal' => 'EUR',
    'Qatar' => 'QAR',
    'République centrafricaine' => 'XAF',
    'République dominicaine' => 'DOP',
    'République tchèque' => 'CZK',
    'Roumanie' => 'RON',
    'Royaume-Uni' => 'GBP',
    'Russie' => 'RUB',
    'Rwanda' => 'RWF',
    'Saint-Christophe-et-Niévès' => 'XCD',
    'Saint-Marin' => 'EUR',
    'Saint-Vincent-et-les-Grenadines' => 'XCD',
    'Sainte-Lucie' => 'XCD',
    'Salomon' => 'SBD',
    'Salvador' => 'USD',
    'Samoa' => 'WST',
    'São Tomé-et-Príncipe' => 'STN',
    'Sénégal' => 'XOF',
    'Serbie' => 'RSD',
    'Seychelles' => 'SCR',
    'Sierra Leone' => 'SLE',
    'Singapour' => 'SGD',
    'Slovaquie' => 'EUR',
    'Slovénie' => 'EUR',
    'Somalie' => 'SOS',
    'Soudan' => 'SDG',
    'Soudan du Sud' => 'SSP',
    'Sri Lanka' => 'LKR',
    'Suède' => 'SEK',
    'Suisse' => 'CHF',
    'Suriname' => 'SRD',
    'Syrie' => 'SYP',
    'Tadjikistan' => 'TJS',
    'Tanzanie' => 'TZS',
    'Tchad' => 'XAF',
    'Thaïlande' => 'THB',
    'Timor oriental' => 'USD',
    'Togo' => 'XOF',
    'Tonga' => 'TOP',
    'Trinité-et-Tobago' => 'TTD',
    'Tunisie' => 'TND',
    'Turkménistan' => 'TMT',
    'Turquie' => 'TRY',
    'Tuvalu' => 'AUD',
    'Ukraine' => 'UAH',
    'Uruguay' => 'UYU',
    'Vanuatu' => 'VUV',
    'Vatican' => 'EUR',
    'Venezuela' => 'VES',
    'Vietnam' => 'VND',
    'Yémen' => 'YER',
    'Zambie' => 'ZMW',
    'Zimbabwe' => 'USD',
];
// Devises sans centimes (montants entiers) ; toutes les autres s'affichent
// avec 2 decimales (la base stocke les montants en DECIMAL(14,2)).
const ZERO_DECIMAL_CURRENCIES = ['XOF', 'XAF', 'XPF', 'KMF', 'DJF', 'GNF', 'RWF', 'UGX', 'BIF', 'CLP', 'JPY', 'KRW', 'PYG', 'VND', 'VUV', 'ISK', 'MGA'];
function currency_for_country($country) { return COUNTRY_CURRENCY[$country] ?? 'XOF'; }
function valid_currency($code) { return in_array($code, COUNTRY_CURRENCY, true); }
function currency_decimals($code) { return in_array($code ?: 'XOF', ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2; }
function money_fmt($amount, $code) { return number_format((float)$amount, currency_decimals($code), ',', ' '); }
// Taux vers le FCFA (1 unite de devise = X FCFA). Les parites FIXES (franc CFA
// d'Afrique centrale, euro a 655,957 par accord officiel, franc comorien
// rattache a l'euro) sont connues d'avance et non modifiables ; les autres
// devises n'ont de taux que si l'admin en a saisi un (table currency_rates).
const FIXED_CFA_RATES = ['XOF' => 1.0, 'XAF' => 1.0, 'EUR' => 655.957, 'KMF' => 1.3333333333];
// Taux saisis a la main par l'admin : ils remplacent le taux du jour.
function currency_rates_custom() {
    static $custom = null;
    if ($custom !== null) return $custom;
    $custom = [];
    try {
        foreach (q("SELECT currency, rate_to_xof FROM currency_rates")->fetchAll() as $r) {
            if (!isset(FIXED_CFA_RATES[$r['currency']])) $custom[$r['currency']] = (float)$r['rate_to_xof'];
        }
    } catch (PDOException $e) { /* table pas encore creee (/install a relancer) */ }
    return $custom;
}
// Taux du jour (ExchangeRate-API, gratuit, sans cle, mis a jour une fois par
// jour cote fournisseur - mention de la source obligatoire, voir la vitrine) :
// recuperes cote serveur au plus toutes les 12 h et gardes en base, pour que
// ni la vitrine ni le panneau admin n'appellent un service tiers a chaque
// visite. Sert aux prix INDICATIFS de la vitrine et au total admin ; jamais
// aux montants factures.
const FX_API_URL = 'https://open.er-api.com/v6/latest/USD';
const FX_MAX_AGE_SECONDS = 43200;
function fx_live_rates() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $m = q("SELECT MAX(updated_at) AS m FROM fx_rates")->fetch()['m'] ?? null;
        if (!$m || strtotime($m) < time() - FX_MAX_AGE_SECONDS) fx_refresh();
        foreach (q("SELECT currency, per_usd FROM fx_rates")->fetchAll() as $r) $cache[$r['currency']] = (float)$r['per_usd'];
    } catch (PDOException $e) { /* table absente : /install a relancer, seuls les taux fixes/saisis servent */ }
    return $cache;
}
function fx_refresh() {
    $ch = curl_init(FX_API_URL);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch);
    curl_close($ch);
    $j = $body ? json_decode($body, true) : null;
    if (!is_array($j) || ($j['result'] ?? '') !== 'success' || empty($j['rates']['XOF'])) {
        // Echec : nouvel essai dans ~15 min (et non a chaque requete) ; les
        // taux deja en base restent utilises en attendant.
        try { q("UPDATE fx_rates SET updated_at = NOW() - INTERVAL '11 hours 45 minutes'"); } catch (PDOException $e) {}
        return false;
    }
    $vals = []; $params = [];
    foreach (array_unique(array_merge(array_values(COUNTRY_CURRENCY), ['USD'])) as $code) {
        if (!empty($j['rates'][$code]) && $j['rates'][$code] > 0) { $vals[] = '(?,?)'; $params[] = $code; $params[] = (float)$j['rates'][$code]; }
    }
    if (!$vals) return false;
    q("INSERT INTO fx_rates (currency, per_usd) VALUES ".implode(',', $vals)."
       ON CONFLICT (currency) DO UPDATE SET per_usd=EXCLUDED.per_usd, updated_at=NOW()", $params);
    return true;
}
// Taux vers le FCFA, par priorite : parite fixe > taux saisi par l'admin >
// taux du jour. Absent = devise sans taux connu.
function currency_rates() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rates = FIXED_CFA_RATES;
    foreach (currency_rates_custom() as $code => $r) $rates[$code] = $r;
    $live = fx_live_rates();
    if (!empty($live['XOF'])) {
        foreach ($live as $code => $perUsd) if (!isset($rates[$code]) && $perUsd > 0) $rates[$code] = $live['XOF'] / $perUsd;
    }
    return $cache = $rates;
}

function require_boutique_owned($boutiqueId, $userId) {
    if (!$boutiqueId) fail('boutique_id manquant', 400);
    $row = q("SELECT * FROM boutiques WHERE id=? AND owner_user_id=?", [$boutiqueId, $userId])->fetch();
    if ($row) { $row['_member_role'] = 'owner'; assert_owner_plan_active($row['owner_user_id']); return $row; }
    // Pas proprietaire : autorise si membre actif de l'equipe de cette
    // boutique (voir route_team()). Meme fonction reutilisee partout plutot
    // que de retoucher chaque module un par un.
    $member = q("SELECT role FROM boutique_members WHERE boutique_id=? AND user_id=? AND status='active'", [$boutiqueId, $userId])->fetch();
    if ($member) {
        $row = q("SELECT * FROM boutiques WHERE id=?", [$boutiqueId])->fetch();
        if ($row) { $row['_member_role'] = $member['role']; assert_owner_plan_active($row['owner_user_id']); return $row; }
    }
    fail('Boutique introuvable', 404);
}
// Bloque l'acces a une boutique si l'abonnement de son PROPRIETAIRE (pas du
// membre d'equipe qui y accede eventuellement) est expire - jamais applique
// aux actions hors boutique (billing, profil...) pour que le proprietaire
// puisse toujours se reabonner lui-meme sans etre bloque de partout.
function assert_owner_plan_active($ownerUserId) {
    $validUntil = q("SELECT plan_valid_until FROM users WHERE id=?", [$ownerUserId])->fetchColumn();
    if ($validUntil !== null && $validUntil !== false && strtotime($validUntil) < time()) {
        fail('L\'abonnement de cette boutique a expire. Le proprietaire doit se reabonner (menu Abonnement) pour continuer a l\'utiliser.', 402);
    }
}
// Reserve les actions sensibles (parametres, gestion d'equipe) au
// proprietaire et aux membres au role 'admin' - les autres roles (manager,
// livreur, closeuse, comptable) gardent acces au reste de la boutique.
function require_boutique_admin($boutiqueId, $userId) {
    $row = require_boutique_owned($boutiqueId, $userId);
    if (!in_array($row['_member_role'], ['owner','admin'], true)) {
        fail('Action reservee au proprietaire ou a un administrateur de la boutique', 403);
    }
    return $row;
}

// Acces par module pour chaque role non-admin (owner/admin passent toujours,
// voir require_module_access). Correspond a la description des roles
// affichee a l'invitation : Manager = tout sauf equipe/parametres/abonnement
// (deja geres a part par require_boutique_admin) ; Livreur ne voit que les
// livraisons (et seulement celles qui lui sont assignees, voir
// deliveries_list()) ; Closeuse = commandes/clients/stock ; Comptable =
// finance/analytique uniquement.
const ROLE_MODULE_ACCESS = [
    'manager'   => ['orders','deliveries','customers','contacts','finance','products','analytics','marketing'],
    'closeuse'  => ['orders','customers','products'],
    'comptable' => ['finance','analytics'],
    'livreur'   => ['deliveries'],
];
// A appeler juste apres require_boutique_owned() dans chaque route_* module
// boutique-scope (voir les routeurs plus bas) - owner/admin ne sont jamais
// restreints, les autres roles doivent figurer dans ROLE_MODULE_ACCESS pour
// ce module precis.
function require_module_access($boutiqueRow, $module) {
    $role = $boutiqueRow['_member_role'] ?? 'owner';
    if (in_array($role, ['owner','admin'], true)) return;
    $allowed = ROLE_MODULE_ACCESS[$role] ?? [];
    if (!in_array($module, $allowed, true)) {
        fail('Votre role n\'a pas acces a cette section', 403);
    }
}
// Interdit une action a des roles precis meme s'ils ont acces au module en
// general (ex: un Livreur peut consulter les livraisons mais ne doit pas
// pouvoir en reassigner une a quelqu'un d'autre).
function deny_roles($boutiqueRow, $roles) {
    if (in_array($boutiqueRow['_member_role'] ?? 'owner', $roles, true)) {
        fail('Action non autorisee pour votre role', 403);
    }
}

// Plans d'abonnement (limite de boutiques par compte). Aucune passerelle de
// paiement automatique branchee : un choix de plan cree une demande
// (subscription_requests) verifiee manuellement par l'operateur de
// MYBOUTIK via /admin (voir route_admin()) avant d'etre activee.
const PLANS = [
    'starter' => ['name'=>'Starter', 'price'=>7000,  'boutique_limit'=>1,  'promo_limit'=>1],
    'pro'     => ['name'=>'Pro',     'price'=>12000, 'boutique_limit'=>3,  'promo_limit'=>5],
    'premium' => ['name'=>'Premium', 'price'=>20000, 'boutique_limit'=>10, 'promo_limit'=>999],
];
// Une commission n'est "disponible" au retrait qu'apres un delai de
// validation (le temps qu'un paiement Mobile Money litigieux soit
// eventuellement annule) - avant cela elle reste "en attente de validation"
// tout en etant deja comptee dans le total gagne. Definies ici (avant le
// routeur plus bas) et non pres de billing_affiliate_info() : un `const`
// top-niveau s'execute a sa position dans le fichier (contrairement a une
// fonction, jamais hoiste) - le declarer apres le routeur le rendait
// "undefined" au moment ou une requete /billing l'utilisait.
const REFERRAL_VALIDATION_DAYS = 7;
const REFERRAL_MIN_PAYOUT = 5000;
const TEAM_ROLES = ['admin','manager','livreur','closeuse','comptable'];

// Point unique d'envoi d'email. Journalise toujours (utile pour deboguer
// meme quand Brevo est branche), puis envoie reellement via l'API Brevo si
// BREVO_API_KEY/BREVO_SENDER_EMAIL sont configures - sinon se comporte comme
// avant (log seul). Ne remonte jamais d'erreur a l'appelant : une commande
// ou une inscription ne doit jamais echouer a cause d'un email qui n'est
// pas parti.
function send_email($to, $subject, $body) {
    error_log('[MYBOUTIK] Email a envoyer -> '.$to.' | Sujet: '.$subject."\n".$body);
    if (!BREVO_API_KEY || !BREVO_SENDER_EMAIL || !$to) return;
    try {
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: '.BREVO_API_KEY],
            CURLOPT_POSTFIELDS => json_encode([
                'sender' => ['email' => BREVO_SENDER_EMAIL, 'name' => BREVO_SENDER_NAME],
                'to' => [['email' => $to]],
                'subject' => $subject,
                'textContent' => $body,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 300) error_log('[MYBOUTIK] Brevo erreur HTTP '.$code.': '.$res);
        curl_close($ch);
    } catch (Throwable $e) {
        error_log('[MYBOUTIK] Brevo exception: '.$e->getMessage());
    }
}

// Paliers de grade (gamification), calcules sur le cumul "vie" des revenus
// encaisses de la boutique. Le cumul ne redescend jamais (meme principe que
// l'ecran "Mon grade" : chaque palier est acquis pour de bon).
const GRADES = [
    ['name'=>'Bronze','min'=>1000000],
    ['name'=>'Argent','min'=>5000000],
    ['name'=>'Or','min'=>10000000],
    ['name'=>'Platine','min'=>20000000],
    ['name'=>'Diamant','min'=>40000000],
    ['name'=>'Emeraude','min'=>80000000],
    ['name'=>'Rubis','min'=>100000000],
    ['name'=>'Maitre','min'=>200000000],
    ['name'=>'Grand Maitre','min'=>300000000],
    ['name'=>'Legende','min'=>500000000],
    ['name'=>'Titan','min'=>1000000000],
];
function compute_grade($totalEncaisse) {
    $current = null; $next = GRADES[0];
    foreach (GRADES as $i => $g) {
        if ($totalEncaisse >= $g['min']) { $current = $g; $next = GRADES[$i+1] ?? null; }
    }
    $prevMin = $current['min'] ?? 0;
    $nextMin = $next['min'] ?? null;
    $progress = $nextMin ? max(0,min(100, (($totalEncaisse-$prevMin)/($nextMin-$prevMin))*100)) : 100;
    return [
        'current_name'  => $current['name'] ?? null,
        'next_name'     => $next['name'] ?? null,
        'total'         => (float)$totalEncaisse,
        'next_min'      => $nextMin,
        'remaining'     => $nextMin ? max(0,$nextMin-$totalEncaisse) : 0,
        'progress_pct'  => round($progress,1),
        'all'           => GRADES,
    ];
}

// ============================================================
// ROUTEUR
// ============================================================
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$parts  = explode('/', $uri);
$module = $parts[1] ?? ($parts[0] ?? '');
$action = $_GET['action'] ?? '';

try {
    switch ($module) {
        case 'install':   route_install(); break;
        case 'auth':      route_auth($action); break;
        case 'boutiques': route_boutiques($action); break;
        case 'products':  route_products($action); break;
        case 'shop':      route_shop($action); break;
        case 'orders':    route_orders($action); break;
        case 'deliveries':route_deliveries($action); break;
        case 'customers': route_customers($action); break;
        case 'contacts':  route_contacts($action); break;
        case 'finance':   route_finance($action); break;
        case 'analytics': route_analytics($action); break;
        case 'marketing': route_marketing($action); break;
        case 'team':      route_team($action); break;
        case 'billing':   route_billing($action); break;
        case 'announcements': route_announcements($action); break;
        case 'feedback':  route_feedback($action); break;
        case 'admin':     route_admin($action); break;
        case 'directory': route_directory($action); break;
        case 'preview':   route_preview($action); break;
        case 'image':     route_image($action); break;
        case 'integrations': route_integrations($action); break;
        case 'cron':      route_cron($action); break;
        case 'health':    ok(['status'=>'up','time'=>date('c')]); break;
        default: fail('Module inconnu', 404);
    }
} catch (PDOException $e) {
    log_and_fail($e, 'Erreur base de donnees');
} catch (Throwable $e) {
    log_and_fail($e, 'Erreur serveur');
}

// ============================================================
// INSTALL — creation des tables
// ============================================================
function route_install() {
    $key = $_GET['key'] ?? '';
    if (APP_ENV !== 'development' && $key !== INSTALL_KEY) fail('Non autorise', 403);

    $sqls = [
    "CREATE TABLE IF NOT EXISTS users (
        id VARCHAR(36) PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(150),
        email_verified_at TIMESTAMP,
        verification_token VARCHAR(64),
        verification_sent_at TIMESTAMP,
        status VARCHAR(20) DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    // Plan/abonnement de la personne (pas de la boutique - un compte avec
    // plusieurs boutiques n'a qu'un seul plan, voir PLANS plus bas).
    // plan_status='pending' le temps qu'un paiement declare soit verifie
    // manuellement (voir subscription_requests) - aucune passerelle de
    // paiement automatique n'est branchee pour l'instant.
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS plan VARCHAR(20) DEFAULT 'starter'",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS plan_status VARCHAR(20) DEFAULT 'active'",
    // Date jusqu'a laquelle l'acces aux boutiques reste actif - un nouveau
    // compte demarre avec 30 jours gratuits sur Starter ; chaque demande
    // d'abonnement approuvee (n'importe quel plan, Starter inclus a 7000
    // FCFA) prolonge de 30 jours (voir admin_subscription_approve()). Passe
    // cette date, require_boutique_owned() bloque l'acces aux boutiques
    // (mais jamais a la page Abonnement elle-meme, pour pouvoir repayer).
    // DEFAULT applique aussi aux comptes deja existants au moment de cette
    // migration : ils repartent avec 30 jours a partir d'aujourd'hui, pas
    // bloques retroactivement sur leur ancienne date d'inscription.
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS plan_valid_until TIMESTAMP DEFAULT (NOW() + INTERVAL '30 days')",
    // Affiliation : code personnel a partager (?ref=CODE), et la personne
    // qui a recrute ce compte (s'il y en a une). La commission (10% du prix
    // du plan) n'est calculee qu'a l'approbation manuelle d'un abonnement -
    // voir admin_subscription_approve() et referral_commissions ci-dessous.
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS referral_code VARCHAR(20) UNIQUE",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS referred_by VARCHAR(36)",
    "CREATE TABLE IF NOT EXISTS referral_commissions (
        id VARCHAR(36) PRIMARY KEY,
        referrer_user_id VARCHAR(36) NOT NULL,
        referred_user_id VARCHAR(36) NOT NULL,
        subscription_request_id VARCHAR(36),
        plan VARCHAR(20),
        amount DECIMAL(14,2) NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        paid_at TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_refcomm_referrer ON referral_commissions(referrer_user_id)",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS referral_clicks INT DEFAULT 0",
    // Numero WhatsApp du marchand (facultatif, renseigne dans Profil) -
    // utilise pour pre-remplir "Mon numero" dans le message WhatsApp
    // "J'ai paye" envoye a l'operateur (voir billing_plans()).
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS whatsapp_number VARCHAR(30)",
    // Mot de passe oublie (auth_forgot_password()/auth_reset_password()) -
    // meme principe que verification_token, mais avec expiration explicite
    // (1h) puisqu'un lien de reinitialisation reste sensible plus longtemps.
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64)",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_expires_at TIMESTAMP",
    // Incremente pour invalider tous les jetons de connexion deja emis (voir
    // owner_auth() qui compare a la valeur 'tv' du jeton) - utilise par
    // auth_logout_other_devices() (vol de telephone) et automatiquement par
    // auth_reset_password() (un mot de passe reinitialise doit aussi couper
    // l'acces a qui utilisait deja une session, potentiel signe de compte
    // compromis).
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS token_version INT DEFAULT 0",
    // Un retrait couvre toujours la totalite du solde "disponible" au
    // moment de la demande (les commissions couvertes passent en
    // status='requested' pour ne pas etre comptees deux fois dans une
    // demande suivante) - verifie manuellement puis marque paye via
    // admin.html, comme les demandes d'abonnement.
    "CREATE TABLE IF NOT EXISTS referral_payouts (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        method VARCHAR(30),
        phone VARCHAR(30),
        status VARCHAR(20) DEFAULT 'requested',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        paid_at TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_refpayouts_user ON referral_payouts(user_id)",
    "CREATE TABLE IF NOT EXISTS subscription_requests (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        plan VARCHAR(20) NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_subreq_user ON subscription_requests(user_id)",
    "CREATE INDEX IF NOT EXISTS idx_subreq_status ON subscription_requests(status)",
    // 'monthly' (30 jours, tarif normal), 'annual' (390 jours = 13x30, tarif
    // 12x le prix mensuel - 1 mois offert), 'free_trial' (30 jours, 0 FCFA,
    // voir is_eligible_for_free_starter_month()) - determine la duree
    // accordee a l'approbation (voir admin_subscription_approve()) et le
    // montant compte comme revenu (voir subscription_request_amount()).
    "ALTER TABLE subscription_requests ADD COLUMN IF NOT EXISTS billing_cycle VARCHAR(12) DEFAULT 'monthly'",
    // Avis sur la plateforme MYBOUTIK elle-meme - soit d'un marchand connecte
    // (user_id renseigne), soit d'un client acheteur anonyme identifie par
    // son telephone/nom saisis a la volee (user_id NULL, customer_* renseignes) -
    // (pas les avis produits/boutique laisses par les clients - voir
    // product_reviews plus bas). L'admin repond par email quand elle est
    // connue (bouton "Repondre" cote panneau admin, voir admin.html), sinon
    // doit recontacter via le telephone fourni - replied_at sert juste a
    // cocher que c'est traite, aucun envoi automatique n'est fait.
    "CREATE TABLE IF NOT EXISTS platform_feedback (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36),
        customer_name VARCHAR(150),
        customer_phone VARCHAR(30),
        customer_email VARCHAR(150),
        rating SMALLINT NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        replied_at TIMESTAMP
    )",
    "ALTER TABLE platform_feedback ALTER COLUMN user_id DROP NOT NULL",
    "ALTER TABLE platform_feedback ADD COLUMN IF NOT EXISTS customer_name VARCHAR(150)",
    "ALTER TABLE platform_feedback ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(30)",
    "ALTER TABLE platform_feedback ADD COLUMN IF NOT EXISTS customer_email VARCHAR(150)",
    "CREATE INDEX IF NOT EXISTS idx_platform_feedback_created ON platform_feedback(created_at DESC)",
    "CREATE TABLE IF NOT EXISTS boutiques (
        id VARCHAR(36) PRIMARY KEY,
        owner_user_id VARCHAR(36) NOT NULL,
        slug VARCHAR(80) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        currency VARCHAR(10) DEFAULT 'XOF',
        cod_enabled SMALLINT DEFAULT 1,
        status VARCHAR(20) DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_boutiques_owner ON boutiques(owner_user_id)",
    // Ajoutee apres le premier lancement - frais de livraison applique
    // automatiquement au panier du client sur la vitrine (voir shop_boutique()
    // et store/index.html). ALTER...IF NOT EXISTS : sans danger a rejouer
    // sur une base qui a deja ete installee.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS default_delivery_fee DECIMAL(14,2) DEFAULT 0",
    // Frais d'expedition par defaut - meme principe que default_delivery_fee
    // mais pour le mode "Expedition" (voir products.shipping_fee et
    // shop_checkout()) : un client choisit l'un ou l'autre au panier, jamais
    // les deux a la fois.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS default_shipping_fee DECIMAL(14,2) DEFAULT 0",
    // Second palier d'expedition : tarif applique quand le pays de
    // destination saisi par le client (voir shop_checkout()) differe du
    // pays de la boutique - une expedition internationale coute
    // generalement plus cher qu'une expedition a l'interieur du pays.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS default_shipping_fee_other_country DECIMAL(14,2) DEFAULT 0",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS description TEXT",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS logo_url TEXT",
    // Opt-in explicite (jamais automatique) pour apparaitre dans l'annuaire
    // public MYBOUTIK (voir route_directory()) - certains marchands vendent
    // en clientele privee et ne veulent pas etre exposes publiquement a
    // cote d'autres boutiques.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS public_listed SMALLINT DEFAULT 0",
    // Categorie/ville optionnelles - uniquement utilisees pour filtrer dans
    // l'annuaire public (route_directory()) ; sans objet pour une boutique
    // qui n'y est pas listee.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS category VARCHAR(60)",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS city VARCHAR(80)",
    // Pays obligatoire a la creation (voir boutiques_create()) - l'annuaire
    // etant public et multi-pays, un acheteur doit pouvoir filtrer pour ne
    // pas commander par erreur chez une boutique trop loin pour etre livre.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS country VARCHAR(80)",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS notify_order_email SMALLINT DEFAULT 1",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS notify_email VARCHAR(190)",
    // notify_whatsapp_enabled/number : colonnes conservees pour compatibilite
    // (evite une migration DROP COLUMN) mais plus utilisees par l'appli -
    // le canal WhatsApp a ete retire, seul l'email reste propose.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS notify_whatsapp_enabled SMALLINT DEFAULT 0",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS notify_whatsapp_number VARCHAR(30)",
    // Import de commandes depuis une feuille Google Sheets publiee en CSV
    // (voir route_integrations()). Pas de synchronisation automatique en
    // arriere-plan (aucun worker planifie sur cet hebergement) : le bouton
    // "Importer maintenant" appelle la meme route a la demande.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS sheet_url TEXT",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS sheet_sync_enabled SMALLINT DEFAULT 0",
    // Alerte de stock bas envoyee au marchand (email/WhatsApp selon ses
    // reglages de notification) via /cron?action=stock_alerts - au plus une
    // fois par jour par boutique (voir last_stock_alert_at).
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS stock_alert_enabled SMALLINT DEFAULT 1",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS last_stock_alert_at TIMESTAMP",
    // Avertissement formel de l'administration (admin_boutique_warn()) -
    // etape intermediaire avant une suspension, avec trace (warning_count)
    // plutot que de passer directement d'"actif" a "suspendu".
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS warning_count INT DEFAULT 0",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS last_warned_at TIMESTAMP",
    // Equipe : une boutique peut etre geree par plusieurs comptes MYBOUTIK
    // distincts (le proprietaire + des membres invites par email). status
    // reste 'pending' (avec un invite_token) tant que la personne invitee
    // n'a pas un compte MYBOUTIK avec cette meme adresse et n'a pas
    // confirme l'invitation - a ce moment user_id est rempli et status
    // passe a 'active'.
    "CREATE TABLE IF NOT EXISTS boutique_members (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        user_id VARCHAR(36),
        email VARCHAR(190) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'manager',
        invite_token VARCHAR(64),
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_boutiquemembers_boutique ON boutique_members(boutique_id)",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_boutiquemembers_boutique_email ON boutique_members(boutique_id, email)",
    // Lie un membre au role 'livreur' a une fiche livreur precise (voir
    // delivery_persons) - lui permet de ne voir que les livraisons qui lui
    // sont assignees plutot que toutes celles de la boutique.
    "ALTER TABLE boutique_members ADD COLUMN IF NOT EXISTS delivery_person_id VARCHAR(36)",
    "CREATE TABLE IF NOT EXISTS products (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(200) NOT NULL,
        description TEXT,
        price DECIMAL(14,2) NOT NULL DEFAULT 0,
        cost_price DECIMAL(14,2),
        stock_qty INT DEFAULT 0,
        image_url TEXT,
        status VARCHAR(20) DEFAULT 'draft',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_products_boutique ON products(boutique_id)",
    // Champs ajoutes apres le premier lancement (fiche produit complete :
    // prix barre, reference interne, suivi de stock, produit
    // physique/service, frais de livraison propre au produit qui prend le
    // pas sur boutiques.default_delivery_fee quand il est renseigne).
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS compare_at_price DECIMAL(14,2)",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS sku VARCHAR(60)",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS barcode VARCHAR(60)",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS slug VARCHAR(100)",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS track_inventory SMALLINT DEFAULT 1",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS allow_backorder SMALLINT DEFAULT 0",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS is_physical SMALLINT DEFAULT 1",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS delivery_fee DECIMAL(14,2)",
    // Frais d'expedition propre au produit - meme principe que delivery_fee
    // (prend le pas sur boutiques.default_shipping_fee quand renseigne),
    // mais pour le mode "Expedition" choisi par le client au panier plutot
    // que "Livraison".
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS shipping_fee DECIMAL(14,2)",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS shipping_fee_other_country DECIMAL(14,2)",
    // Independant de is_physical : un produit peut etre physique ET
    // numerique a la fois (ex: une boite livree qui contient aussi un code
    // d'activation envoye par email), l'un n'exclut pas l'autre. is_physical
    // controle uniquement les frais de livraison (voir shop_checkout()) ;
    // is_digital controle uniquement l'envoi automatique du contenu
    // numerique (voir maybe_send_digital_delivery()).
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS is_digital SMALLINT DEFAULT 0",
    // Produit numerique (is_physical=0) : contenu envoye automatiquement par
    // email au client quand la commande passe au statut "livree". Deux
    // sources cumulables : digital_delivery_content (meme contenu partage
    // pour toutes les ventes - lien, instructions...) et un pool de codes a
    // usage unique dans product_digital_codes (licences, codes cadeaux...),
    // un code different pioche a chaque vente. Les deux peuvent etre
    // renseignes ensemble (ex: meme lien de telechargement pour tous + une
    // cle de licence differente par acheteur) - voir maybe_send_digital_delivery().
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS digital_delivery_content TEXT",
    // digital_delivery_mode : colonne conservee pour compatibilite (evite une
    // migration DROP COLUMN) mais plus utilisee - les deux sources ci-dessus
    // se cumulent desormais au lieu d'etre un choix exclusif.
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS digital_delivery_mode VARCHAR(20) DEFAULT 'link'",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS digital_delivery_sent_at TIMESTAMP",
    "CREATE TABLE IF NOT EXISTS product_digital_codes (
        id VARCHAR(36) PRIMARY KEY,
        product_id VARCHAR(36) NOT NULL,
        code TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'available',
        used_by_order_id VARCHAR(36),
        used_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT NOW()
    )",
    "CREATE INDEX IF NOT EXISTS idx_digital_codes_product ON product_digital_codes(product_id, status)",
    // Definition des options (Taille, Couleur...) ayant servi a generer les
    // variantes - stockee telle quelle (JSON) pour pouvoir rouvrir le
    // generateur avec les memes lignes plutot que de les reconstruire a
    // partir des noms de variantes deja crees.
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS options_json TEXT",
    // Une commande n'est plus jamais bloquee par manque de stock (au
    // marchand de gerer un stock insuffisant une fois la commande vue,
    // pas a l'acheteur de s'en rendre compte au moment de payer) - a la
    // place, le marchand definit lui-meme un seuil pour etre alerte quand
    // le stock d'un produit devient bas.
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_threshold INT DEFAULT 5",
    // Produits associes choisis a la main par le marchand pour l'upsell sur
    // la fiche produit de la vitrine (liste d'ids JSON, resolue a l'affichage
    // - voir shop_product()). Pas de suggestion automatique par categorie :
    // aucune notion de categorie n'existe encore dans le modele.
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS related_product_ids TEXT",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_products_boutique_slug ON products(boutique_id, slug) WHERE slug IS NOT NULL AND slug <> ''",
    // Categories de produits, pour organiser/filtrer le catalogue une fois
    // qu'il grandit (menu Produits + filtre sur la vitrine publique).
    "CREATE TABLE IF NOT EXISTS product_categories (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_productcategories_boutique ON product_categories(boutique_id)",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS category_id VARCHAR(36)",
    "CREATE INDEX IF NOT EXISTS idx_products_category ON products(category_id)",
    // Galerie de photos (plusieurs images par produit). products.image_url
    // reste en place et reflete toujours l'image marquee is_primary=1 ici -
    // tout le code existant qui lit deja image_url (liste produits, vitrine,
    // panier) continue de fonctionner sans modification.
    "CREATE TABLE IF NOT EXISTS product_images (
        id VARCHAR(36) PRIMARY KEY,
        product_id VARCHAR(36) NOT NULL,
        boutique_id VARCHAR(36) NOT NULL,
        data TEXT NOT NULL,
        position INT DEFAULT 0,
        is_primary SMALLINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_productimages_product ON product_images(product_id)",
    "CREATE TABLE IF NOT EXISTS product_variants (
        id VARCHAR(36) PRIMARY KEY,
        product_id VARCHAR(36) NOT NULL,
        name VARCHAR(100) NOT NULL,
        price DECIMAL(14,2),
        stock_qty INT DEFAULT 0
    )",
    "CREATE INDEX IF NOT EXISTS idx_variants_product ON product_variants(product_id)",
    "CREATE TABLE IF NOT EXISTS suppliers (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(30),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_suppliers_boutique ON suppliers(boutique_id)",
    "CREATE TABLE IF NOT EXISTS supplier_orders (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        supplier_id VARCHAR(36) NOT NULL,
        product_id VARCHAR(36),
        qty INT NOT NULL,
        unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_supplierorders_boutique ON supplier_orders(boutique_id)",
    "CREATE TABLE IF NOT EXISTS customers (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(150),
        phone VARCHAR(30),
        email VARCHAR(190),
        address TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_boutique_phone ON customers(boutique_id, phone)",
    "CREATE TABLE IF NOT EXISTS orders (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        customer_id VARCHAR(36),
        ref VARCHAR(30) UNIQUE,
        status VARCHAR(20) DEFAULT 'pending',
        payment_method VARCHAR(20) DEFAULT 'cod',
        subtotal DECIMAL(14,2) DEFAULT 0,
        delivery_fee_charged DECIMAL(14,2) DEFAULT 0,
        total DECIMAL(14,2) DEFAULT 0,
        customer_name VARCHAR(150),
        customer_phone VARCHAR(30),
        customer_address TEXT,
        utm_source VARCHAR(100),
        utm_campaign VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        delivered_at TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_orders_boutique ON orders(boutique_id)",
    "CREATE INDEX IF NOT EXISTS idx_orders_boutique_created ON orders(boutique_id, created_at)",
    // Reference externe (id_commande de la feuille Google Sheets importee) -
    // sert uniquement a ne jamais importer deux fois la meme ligne.
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS external_ref VARCHAR(64)",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_boutique_external_ref ON orders(boutique_id, external_ref) WHERE external_ref IS NOT NULL AND external_ref <> ''",
    // Code promo applique a la commande (reduction deja deduite dans total -
    // subtotal/delivery_fee_charged restent les montants bruts, discount_amount
    // est ce qui a ete retire).
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS promo_code VARCHAR(40)",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(14,2) DEFAULT 0",
    // 'delivery' ou 'shipping' - mode choisi par le client au panier quand la
    // commande contient un produit physique (voir shop_checkout()) ;
    // delivery_fee_charged porte le montant reellement facture dans les
    // deux cas, cette colonne dit juste lequel des deux tarifs s'applique.
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_method VARCHAR(20) DEFAULT 'delivery'",
    // Pays de destination saisi par le client en mode "Expedition" - sert a
    // determiner cote serveur si le tarif "meme pays" ou "autre pays"
    // s'applique (voir shop_checkout()) ; NULL en mode "Livraison" ou pour
    // les commandes 100% numeriques.
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_country VARCHAR(80)",
    // Reclamation client apres livraison ("le produit ne correspond pas a
    // mes attentes") - portee directement par la commande plutot qu'une
    // table a part : une seule reclamation active a la fois par commande
    // suffit pour ce cas d'usage, pas besoin d'un fil de discussion.
    // dispute_status : NULL (aucune), 'open' (en attente du marchand),
    // 'resolved' (le marchand a repondu).
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS dispute_status VARCHAR(20)",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS dispute_message TEXT",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS dispute_created_at TIMESTAMP",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS dispute_response TEXT",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS dispute_resolved_at TIMESTAMP",
    "CREATE TABLE IF NOT EXISTS order_items (
        id VARCHAR(36) PRIMARY KEY,
        order_id VARCHAR(36) NOT NULL,
        product_id VARCHAR(36),
        product_name VARCHAR(200),
        variant_id VARCHAR(36),
        unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
        unit_cost DECIMAL(14,2) DEFAULT 0,
        qty INT NOT NULL DEFAULT 1
    )",
    "CREATE INDEX IF NOT EXISTS idx_items_order ON order_items(order_id)",
    "CREATE TABLE IF NOT EXISTS abandoned_carts (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        session_id VARCHAR(64),
        phone VARCHAR(30),
        email VARCHAR(190),
        cart_snapshot TEXT,
        total DECIMAL(14,2) DEFAULT 0,
        converted SMALLINT DEFAULT 0,
        captured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_abandoned_boutique ON abandoned_carts(boutique_id)",
    "CREATE TABLE IF NOT EXISTS abandoned_settings (
        boutique_id VARCHAR(36) PRIMARY KEY,
        capture_enabled SMALLINT DEFAULT 1,
        timeout_minutes INT DEFAULT 15,
        email_alert SMALLINT DEFAULT 0
    )",
    // Relance automatique du CLIENT (par opposition a email_alert ci-dessus,
    // qui prevenait le marchand) - envoyee une seule fois par panier via
    // /cron?action=abandoned_reminders (voir abandoned_carts.reminded_at).
    "ALTER TABLE abandoned_settings ADD COLUMN IF NOT EXISTS customer_reminder_enabled SMALLINT DEFAULT 0",
    "ALTER TABLE abandoned_carts ADD COLUMN IF NOT EXISTS reminded_at TIMESTAMP",
    "CREATE TABLE IF NOT EXISTS delivery_persons (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(30),
        active SMALLINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_delivpersons_boutique ON delivery_persons(boutique_id)",
    "ALTER TABLE delivery_persons ADD COLUMN IF NOT EXISTS email VARCHAR(190)",
    "ALTER TABLE delivery_persons ADD COLUMN IF NOT EXISTS vehicle_type VARCHAR(20)",
    "ALTER TABLE delivery_persons ADD COLUMN IF NOT EXISTS plate_number VARCHAR(30)",
    "ALTER TABLE delivery_persons ADD COLUMN IF NOT EXISTS photo_url TEXT",
    "ALTER TABLE delivery_persons ADD COLUMN IF NOT EXISTS notes TEXT",
    "CREATE TABLE IF NOT EXISTS delivery_assignments (
        id VARCHAR(36) PRIMARY KEY,
        order_id VARCHAR(36) NOT NULL UNIQUE,
        boutique_id VARCHAR(36) NOT NULL,
        delivery_person_id VARCHAR(36),
        status VARCHAR(20) DEFAULT 'to_assign',
        assigned_at TIMESTAMP,
        delivered_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_delivassign_boutique ON delivery_assignments(boutique_id)",
    "CREATE TABLE IF NOT EXISTS contact_roles (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(100) NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS contacts (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        role_id VARCHAR(36),
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(30),
        email VARCHAR(190),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_contacts_boutique ON contacts(boutique_id)",
    "CREATE TABLE IF NOT EXISTS accounts (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(100) NOT NULL,
        type VARCHAR(20) DEFAULT 'caisse',
        balance DECIMAL(14,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_accounts_boutique ON accounts(boutique_id)",
    "CREATE TABLE IF NOT EXISTS account_transactions (
        id VARCHAR(36) PRIMARY KEY,
        account_id VARCHAR(36) NOT NULL,
        boutique_id VARCHAR(36) NOT NULL,
        type VARCHAR(10) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        note TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_accounttx_account ON account_transactions(account_id)",
    "CREATE TABLE IF NOT EXISTS delivery_fees_paid (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        order_id VARCHAR(36),
        amount DECIMAL(14,2) NOT NULL,
        note TEXT,
        account_id VARCHAR(36),
        paid_at DATE DEFAULT CURRENT_DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_deliveryfees_boutique ON delivery_fees_paid(boutique_id)",
    // Livreur a qui ce frais a ete verse - permet de retrouver qui a ete
    // paye pour quelle livraison en cas de litige/malentendu (order_id
    // existait deja mais n'etait jamais rempli par le formulaire du
    // tableau de bord, voir finance_delivery_fee_create()).
    "ALTER TABLE delivery_fees_paid ADD COLUMN IF NOT EXISTS delivery_person_id VARCHAR(36)",
    "CREATE TABLE IF NOT EXISTS expenses (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        label VARCHAR(200) NOT NULL,
        category VARCHAR(60),
        amount DECIMAL(14,2) NOT NULL,
        account_id VARCHAR(36),
        expense_date DATE DEFAULT CURRENT_DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_expenses_boutique ON expenses(boutique_id)",
    "CREATE TABLE IF NOT EXISTS ad_expenses (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        campaign_name VARCHAR(150),
        product_id VARCHAR(36),
        amount DECIMAL(14,2) NOT NULL,
        spend_date DATE DEFAULT CURRENT_DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_adexpenses_boutique ON ad_expenses(boutique_id)",
    // Plusieurs produits peuvent partager une meme depense publicitaire
    // (ex: un carrousel Facebook qui met en avant 3 articles a la fois).
    // ad_expenses.product_id reste pour compat (une seule ligne = un seul
    // produit dans l'ancien systeme) mais n'est plus la source de verite :
    // l'INSERT ci-dessous copie une bonne fois les anciennes lignes dans
    // cette table, qui est ensuite la seule utilisee pour calculer le ROAS
    // par produit (voir finance_ads()).
    "CREATE TABLE IF NOT EXISTS ad_expense_products (
        ad_expense_id VARCHAR(36) NOT NULL,
        product_id VARCHAR(36) NOT NULL,
        PRIMARY KEY (ad_expense_id, product_id)
    )",
    "CREATE INDEX IF NOT EXISTS idx_adexpenseproducts_product ON ad_expense_products(product_id)",
    "INSERT INTO ad_expense_products (ad_expense_id, product_id)
     SELECT id, product_id FROM ad_expenses WHERE product_id IS NOT NULL
     ON CONFLICT DO NOTHING",
    // Codes de reduction utilisables au panier de la vitrine (type
    // percent = %, amount = montant fixe retire du sous-total). L'unicite du
    // code par boutique ignore la casse (index sur UPPER(code)) pour eviter
    // qu'un client tape "PROMO10" alors que le marchand a saisi "promo10".
    "CREATE TABLE IF NOT EXISTS promo_codes (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        code VARCHAR(40) NOT NULL,
        type VARCHAR(10) NOT NULL DEFAULT 'percent',
        value DECIMAL(14,2) NOT NULL,
        max_uses INT,
        used_count INT DEFAULT 0,
        expires_at TIMESTAMP,
        active SMALLINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_promocodes_boutique_code ON promo_codes(boutique_id, UPPER(code))",
    "CREATE INDEX IF NOT EXISTS idx_promocodes_boutique ON promo_codes(boutique_id)",
    // Ciblage par client (optionnel) : un code SANS aucune ligne ici reste
    // ouvert a tous (comportement d'origine). Des qu'au moins un numero est
    // associe, le code devient exclusif a ces numeros - voir
    // find_active_promo(), qui refuse toute autre commande meme si le
    // client a obtenu le code par un tiers.
    "CREATE TABLE IF NOT EXISTS promo_code_customers (
        promo_code_id VARCHAR(36) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        PRIMARY KEY (promo_code_id, phone)
    )",
    "CREATE INDEX IF NOT EXISTS idx_promocodecustomers_promo ON promo_code_customers(promo_code_id)",
    // Avis clients sur une fiche produit - modere par le marchand
    // (status pending/approved/rejected) avant d'apparaitre sur la vitrine,
    // pour eviter le spam/les faux avis visibles immediatement.
    "CREATE TABLE IF NOT EXISTS product_reviews (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        product_id VARCHAR(36) NOT NULL,
        customer_name VARCHAR(150) NOT NULL,
        rating INT NOT NULL,
        comment TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_reviews_product ON product_reviews(product_id)",
    "CREATE INDEX IF NOT EXISTS idx_reviews_boutique ON product_reviews(boutique_id)",
    // Promotion automatiquement visible sur les fiches produits ciblees
    // (contrairement aux codes promo, aucune saisie du client - le prix
    // barre/reduit s'affiche tout seul, voir effective_unit_price()).
    "CREATE TABLE IF NOT EXISTS product_promotions (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        type VARCHAR(10) NOT NULL DEFAULT 'percent',
        value DECIMAL(14,2) NOT NULL,
        starts_at TIMESTAMP,
        expires_at TIMESTAMP,
        active SMALLINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_productpromotions_boutique ON product_promotions(boutique_id)",
    "CREATE TABLE IF NOT EXISTS product_promotion_items (
        promotion_id VARCHAR(36) NOT NULL,
        product_id VARCHAR(36) NOT NULL,
        PRIMARY KEY (promotion_id, product_id)
    )",
    "CREATE INDEX IF NOT EXISTS idx_productpromotionitems_product ON product_promotion_items(product_id)",
    "CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        email VARCHAR(190) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_newsletter_boutique_email ON newsletter_subscribers(boutique_id, email)",
    "CREATE TABLE IF NOT EXISTS contact_messages (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(150),
        email VARCHAR(190),
        message TEXT,
        is_read SMALLINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_contactmsg_boutique ON contact_messages(boutique_id)",
    "CREATE TABLE IF NOT EXISTS visits (
        id SERIAL PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        session_id VARCHAR(64),
        path VARCHAR(255),
        referrer VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_visits_boutique_created ON visits(boutique_id, created_at)",
    "CREATE TABLE IF NOT EXISTS activity_log (
        id SERIAL PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    // Auteur de l'action, pour la page Historique - absent (NULL) pour les
    // actions declenchees par un client sur la vitrine publique (personne
    // connectee dans ce cas), rempli pour toute action venant du tableau de
    // bord (voir log_activity()).
    "ALTER TABLE activity_log ADD COLUMN IF NOT EXISTS actor_email VARCHAR(190)",
    "CREATE INDEX IF NOT EXISTS idx_activity_boutique_created ON activity_log(boutique_id, created_at)",
    "CREATE TABLE IF NOT EXISTS rate_limit_hits (
        id SERIAL PRIMARY KEY,
        bucket VARCHAR(50),
        ip_address VARCHAR(64),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    // Taux de change saisis par l'admin (1 unite de la devise = rate_to_xof
    // FCFA) - sert uniquement aux totaux/classements du panneau admin.
    "CREATE TABLE IF NOT EXISTS currency_rates (
        currency VARCHAR(3) PRIMARY KEY,
        rate_to_xof DECIMAL(18,6) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    // Taux du jour (base USD) recuperes automatiquement et gardes ~12 h - voir
    // fx_live_rates() ; les taux saisis par l'admin (currency_rates) priment.
    "CREATE TABLE IF NOT EXISTS fx_rates (
        currency VARCHAR(3) PRIMARY KEY,
        per_usd DECIMAL(24,10) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    // Annonces de l'admin affichees en bandeau sur le tableau de bord des
    // marchands (voir route_announcements()) ; une ligne par marchand qui a
    // ferme une annonce pour ne plus la lui montrer.
    "CREATE TABLE IF NOT EXISTS announcements (
        id VARCHAR(36) PRIMARY KEY,
        title VARCHAR(120),
        message TEXT NOT NULL,
        level VARCHAR(10) DEFAULT 'info',
        audience VARCHAR(20) DEFAULT 'all',
        audience_value VARCHAR(80),
        starts_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ends_at TIMESTAMP,
        active SMALLINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS announcement_dismissals (
        announcement_id VARCHAR(36) NOT NULL,
        user_id VARCHAR(36) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (announcement_id, user_id)
    )",
    ];

    $created = [];
    foreach ($sqls as $sql) {
        try {
            db()->exec($sql);
            preg_match('/TABLE IF NOT EXISTS (\w+)/', $sql, $m);
            if (!empty($m[1])) $created[] = $m[1];
        } catch (Exception $e) {
            fail('Erreur SQL: '.$e->getMessage(), 500);
        }
    }

    ok(['tables_created'=>$created], 'Installation terminee ! Toutes les tables ont ete creees.');
}

// ============================================================
// AUTH — inscription, verification email, connexion
// ============================================================
function route_auth($action) {
    switch ($action) {
        case 'register': auth_register(); break;
        case 'verify':   auth_verify(); break;
        case 'resend':   auth_resend(); break;
        case 'login':    auth_login(); break;
        case 'me':       auth_me(); break;
        case 'profile_update': auth_profile_update(); break;
        case 'track_ref_click': auth_track_ref_click(); break;
        case 'forgot_password': auth_forgot_password(); break;
        case 'reset_password':  auth_reset_password(); break;
        case 'logout_other_devices': auth_logout_other_devices(); break;
        default: fail('Action inconnue', 404);
    }
}

function generate_unique_referral_code() {
    do { $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 7)); }
    while (q("SELECT 1 FROM users WHERE referral_code=?", [$code])->fetch());
    return $code;
}

// Public (aucune authentification) : compte un clic sur un lien d'affiliation
// avant meme une eventuelle inscription, pour que la page Affiliation montre
// un vrai taux de conversion (clics -> inscriptions -> abonnes payants).
function auth_track_ref_click() {
    rate_limit_check('ref_click', 60, 300);
    $code = trim(bg('code', ''));
    if ($code === '') ok(null);
    q("UPDATE users SET referral_clicks = referral_clicks + 1 WHERE referral_code=?", [$code]);
    ok(null);
}

function auth_register() {
    rate_limit_check('auth_register', 10, 300);
    $b = body();
    $email = strtolower(trim($b['email'] ?? ''));
    $password = (string)($b['password'] ?? '');
    $fullName = trim($b['full_name'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse email invalide');
    if (strlen($password) < 6) fail('Le mot de passe doit contenir au moins 6 caracteres');

    $exists = q("SELECT id FROM users WHERE email=?", [$email])->fetch();
    if ($exists) fail('Un compte existe deja avec cet email', 409);

    $id = uid();
    $token = bin2hex(random_bytes(24));
    $myReferralCode = generate_unique_referral_code();
    $referredBy = null;
    $refCode = trim($b['ref'] ?? '');
    if ($refCode !== '') {
        $referrer = q("SELECT id FROM users WHERE referral_code=?", [$refCode])->fetch();
        if ($referrer) $referredBy = $referrer['id'];
    }
    q("INSERT INTO users (id,email,password_hash,full_name,verification_token,verification_sent_at,referral_code,referred_by)
       VALUES (?,?,?,?,?,NOW(),?,?)",
      [$id, $email, password_hash($password, PASSWORD_DEFAULT), $fullName, $token, $myReferralCode, $referredBy]);

    $verifyLink = auth_verify_link($token, $b['page_url'] ?? '');
    send_email($email, 'Verifiez votre email MYBOUTIK', "Cliquez sur ce lien pour activer votre compte :\n".$verifyLink);

    $data = ['email' => $email];
    if (APP_ENV === 'development') $data['verify_link_dev_only'] = $verifyLink;
    ok($data, 'Compte cree. Verifiez votre email pour activer votre compte.', 201);
}

function auth_verify_link($token, $pageUrl = '') {
    // Construit un lien pointant vers la page d'accueil (index.html), qui
    // lit ?verify=TOKEN au chargement pour appeler auth?action=verify.
    // $pageUrl vient du frontend (window.location.href sans la query string)
    // et inclut le sous-dossier eventuel (ex: /myboutik/ sur GitHub Pages) -
    // l'en-tete Origin seul (repli si $pageUrl est absent/invalide) ne
    // contient jamais ce sous-dossier, ce qui produirait un lien casse des
    // que le site n'est pas a la racine du domaine.
    $pageUrl = trim($pageUrl);
    if ($pageUrl !== '' && preg_match('#^https?://#i', $pageUrl)) {
        $base = rtrim(strtok($pageUrl, '?#'), '/');
    } else {
        $base = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
    }
    // Pas de "/" ajoute ici : $base contient deja le chemin complet envoye
    // par le frontend, fichier inclus (ex: ".../myboutik/index.html"). En
    // ajouter un produisait "index.html/?verify=..." -> 404 sur GitHub Pages
    // (qui ne reecrit pas les URLs comme un serveur PHP classique).
    return $base.'?verify='.$token;
}

function auth_verify() {
    $token = bg('token');
    if (!$token) fail('Token manquant');
    $user = q("SELECT id FROM users WHERE verification_token=?", [$token])->fetch();
    if (!$user) fail('Lien de verification invalide ou deja utilise', 404);
    q("UPDATE users SET email_verified_at=NOW(), verification_token=NULL WHERE id=?", [$user['id']]);
    ok(null, 'Email verifie. Vous pouvez vous connecter.');
}

function auth_resend() {
    rate_limit_check('auth_resend', 5, 300);
    $email = strtolower(trim(bg('email','')));
    $user = q("SELECT id,email_verified_at FROM users WHERE email=?", [$email])->fetch();
    // Reponse volontairement identique que l'email existe ou non, pour ne
    // pas laisser deviner quels emails sont deja inscrits.
    if ($user && !$user['email_verified_at']) {
        $token = bin2hex(random_bytes(24));
        q("UPDATE users SET verification_token=?, verification_sent_at=NOW() WHERE id=?", [$token, $user['id']]);
        send_email($email, 'Verifiez votre email MYBOUTIK', "Cliquez sur ce lien pour activer votre compte :\n".auth_verify_link($token, bg('page_url','')));
    }
    ok(null, 'Si un compte existe avec cet email, un nouveau lien vient d\'etre envoye.');
}

function auth_login() {
    rate_limit_check('auth_login', 20, 300);
    $b = body();
    $email = strtolower(trim($b['email'] ?? ''));
    $password = (string)($b['password'] ?? '');
    $user = q("SELECT * FROM users WHERE email=?", [$email])->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) fail('Email ou mot de passe incorrect', 401);
    if ($user['status'] !== 'active') fail('Compte suspendu ou bloque', 403);
    if (!$user['email_verified_at']) fail('Veuillez verifier votre email avant de vous connecter', 403);

    $token = jwt_make(['sub'=>$user['id'], 'typ'=>'owner', 'tv'=>(int)($user['token_version'] ?? 0)]);
    ok(['token'=>$token, 'user'=>[
        'id'=>$user['id'], 'email'=>$user['email'], 'full_name'=>$user['full_name'],
    ]], 'Connecte');
}

// Meme construction que auth_verify_link() (voir ce commentaire pour le
// detail du sous-dossier GitHub Pages), juste un parametre different.
function auth_reset_link($token, $pageUrl = '') {
    $pageUrl = trim($pageUrl);
    if ($pageUrl !== '' && preg_match('#^https?://#i', $pageUrl)) {
        $base = rtrim(strtok($pageUrl, '?#'), '/');
    } else {
        $base = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
    }
    return $base.'?reset_token='.$token;
}

function auth_forgot_password() {
    rate_limit_check('auth_forgot_password', 5, 300);
    $email = strtolower(trim(bg('email','')));
    $user = q("SELECT id FROM users WHERE email=?", [$email])->fetch();
    // Meme reponse que l'email existe ou non (voir auth_resend()) - evite
    // de laisser deviner quels emails sont inscrits sur la plateforme.
    if ($user) {
        $token = bin2hex(random_bytes(24));
        q("UPDATE users SET reset_token=?, reset_token_expires_at=NOW() + INTERVAL '1 hour' WHERE id=?", [$token, $user['id']]);
        send_email($email, 'Reinitialisation de votre mot de passe MYBOUTIK',
            "Cliquez sur ce lien pour choisir un nouveau mot de passe (valable 1 heure) :\n".auth_reset_link($token, bg('page_url','')).
            "\n\nSi vous n'etes pas a l'origine de cette demande, ignorez simplement cet email.");
    }
    ok(null, 'Si un compte existe avec cet email, un lien de reinitialisation vient d\'etre envoye.');
}

function auth_reset_password() {
    rate_limit_check('auth_reset_password', 10, 300);
    $b = body();
    $token = trim($b['token'] ?? '');
    $newPassword = (string)($b['new_password'] ?? '');
    if ($token === '') fail('Lien de reinitialisation invalide');
    if (strlen($newPassword) < 6) fail('Le nouveau mot de passe doit contenir au moins 6 caracteres');
    $user = q("SELECT id FROM users WHERE reset_token=? AND reset_token_expires_at > NOW()", [$token])->fetch();
    if (!$user) fail('Lien de reinitialisation invalide ou expire', 404);
    // token_version incremente : un mot de passe oublie puis reinitialise
    // est un signe possible de compte compromis - ca coupe aussi l'acces a
    // qui aurait deja une session ouverte sur un appareil vole/perdu (voir
    // owner_auth()), pas seulement a celui qui reinitialise.
    q("UPDATE users SET password_hash=?, reset_token=NULL, reset_token_expires_at=NULL, token_version=token_version+1 WHERE id=?",
      [password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
    ok(null, 'Mot de passe reinitialise. Vous pouvez maintenant vous connecter.');
}

// Invalide tous les jetons deja emis (autres appareils, y compris un
// telephone vole) en incrementant token_version, puis renvoie un nouveau
// jeton pour CET appareil-ci (sinon sa propre session, deja emise avec
// l'ancienne valeur, se deconnecterait aussi au prochain appel).
function auth_logout_other_devices() {
    $pl = owner_auth();
    q("UPDATE users SET token_version=token_version+1 WHERE id=?", [$pl['sub']]);
    $newTv = (int)q("SELECT token_version FROM users WHERE id=?", [$pl['sub']])->fetchColumn();
    $newToken = jwt_make(['sub'=>$pl['sub'], 'typ'=>'owner', 'tv'=>$newTv]);
    ok(['token'=>$newToken], 'Deconnecte de tous les autres appareils. Cette session reste active.');
}

function auth_me() {
    $pl = owner_auth();
    $user = q("SELECT id,email,full_name,plan,plan_status,whatsapp_number,created_at FROM users WHERE id=?", [$pl['sub']])->fetch();
    if (!$user) fail('Compte introuvable', 404);
    ok($user);
}

function auth_profile_update() {
    $pl = owner_auth();
    $b = body();
    $user = q("SELECT * FROM users WHERE id=?", [$pl['sub']])->fetch();
    $fullName = trim($b['full_name'] ?? $user['full_name']);
    $whatsapp = trim($b['whatsapp_number'] ?? $user['whatsapp_number']);
    q("UPDATE users SET full_name=?, whatsapp_number=? WHERE id=?", [$fullName, $whatsapp, $user['id']]);
    if (!empty($b['new_password'])) {
        if (strlen($b['new_password']) < 6) fail('Le nouveau mot de passe doit contenir au moins 6 caracteres');
        q("UPDATE users SET password_hash=? WHERE id=?", [password_hash($b['new_password'], PASSWORD_DEFAULT), $user['id']]);
    }
    ok(null, 'Profil mis a jour');
}

// ============================================================
// BOUTIQUES — creation et gestion multi-boutiques d'un proprietaire
// ============================================================
function route_boutiques($action) {
    $pl = owner_auth();
    switch ($action) {
        case 'list':   boutiques_list($pl); break;
        case 'create': boutiques_create($pl); break;
        case 'update': boutiques_update($pl); break;
        case 'get':    boutiques_get($pl); break;
        case 'grade':  boutiques_grade($pl); break;
        case 'export_backup': boutiques_export_backup($pl); break;
        default: fail('Action inconnue', 404);
    }
}

// Sauvegarde complete d'une boutique en JSON - reservee au proprietaire/admin
// (donnees clients/commandes/finances sensibles, voir require_boutique_admin).
// Les photos (base64) sont volontairement exclues pour garder le fichier
// leger et rapide a generer ; tout le reste (catalogue, clients, commandes,
// finances, marketing) y est.
function boutiques_export_backup($pl) {
    $bt = require_boutique_admin($_GET['boutique_id'] ?? '', $pl['sub']);
    $bid = $bt['id'];
    $data = [
        'exported_at' => date('c'),
        'boutique' => q("SELECT id,slug,name,description,currency,cod_enabled,default_delivery_fee,default_shipping_fee,default_shipping_fee_other_country,status,created_at FROM boutiques WHERE id=?", [$bid])->fetch(),
        'products' => q("SELECT id,name,description,price,compare_at_price,cost_price,stock_qty,status,sku,barcode,slug,track_inventory,is_physical,is_digital,delivery_fee,shipping_fee,shipping_fee_other_country,low_stock_threshold,category_id,created_at FROM products WHERE boutique_id=?", [$bid])->fetchAll(),
        'product_variants' => q("SELECT v.* FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.boutique_id=?", [$bid])->fetchAll(),
        'product_categories' => q("SELECT * FROM product_categories WHERE boutique_id=?", [$bid])->fetchAll(),
        'customers' => q("SELECT * FROM customers WHERE boutique_id=?", [$bid])->fetchAll(),
        'orders' => q("SELECT * FROM orders WHERE boutique_id=?", [$bid])->fetchAll(),
        'order_items' => q("SELECT oi.* FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.boutique_id=?", [$bid])->fetchAll(),
        'contacts' => q("SELECT * FROM contacts WHERE boutique_id=?", [$bid])->fetchAll(),
        'contact_roles' => q("SELECT * FROM contact_roles WHERE boutique_id=?", [$bid])->fetchAll(),
        'delivery_persons' => q("SELECT id,name,phone,email,vehicle_type,plate_number,active,notes,created_at FROM delivery_persons WHERE boutique_id=?", [$bid])->fetchAll(),
        'delivery_assignments' => q("SELECT * FROM delivery_assignments WHERE boutique_id=?", [$bid])->fetchAll(),
        'delivery_fees_paid' => q("SELECT * FROM delivery_fees_paid WHERE boutique_id=?", [$bid])->fetchAll(),
        'accounts' => q("SELECT * FROM accounts WHERE boutique_id=?", [$bid])->fetchAll(),
        'account_transactions' => q("SELECT * FROM account_transactions WHERE boutique_id=?", [$bid])->fetchAll(),
        'expenses' => q("SELECT * FROM expenses WHERE boutique_id=?", [$bid])->fetchAll(),
        'ad_expenses' => q("SELECT * FROM ad_expenses WHERE boutique_id=?", [$bid])->fetchAll(),
        'suppliers' => q("SELECT * FROM suppliers WHERE boutique_id=?", [$bid])->fetchAll(),
        'supplier_orders' => q("SELECT * FROM supplier_orders WHERE boutique_id=?", [$bid])->fetchAll(),
        'promo_codes' => q("SELECT * FROM promo_codes WHERE boutique_id=?", [$bid])->fetchAll(),
        'product_reviews' => q("SELECT * FROM product_reviews WHERE boutique_id=?", [$bid])->fetchAll(),
        'newsletter_subscribers' => q("SELECT * FROM newsletter_subscribers WHERE boutique_id=?", [$bid])->fetchAll(),
        'contact_messages' => q("SELECT * FROM contact_messages WHERE boutique_id=?", [$bid])->fetchAll(),
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="sauvegarde-'.$bt['slug'].'-'.date('Y-m-d').'.json"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function boutiques_list($pl) {
    // Les boutiques dont l'utilisateur est proprietaire ET celles ou il a
    // ete ajoute comme membre d'equipe actif (voir require_boutique_owned).
    $rows = q("SELECT b.*, 'owner' AS my_role FROM boutiques b WHERE b.owner_user_id=?
               UNION
               SELECT b.*, bm.role AS my_role FROM boutiques b
               JOIN boutique_members bm ON bm.boutique_id=b.id
               WHERE bm.user_id=? AND bm.status='active'
               ORDER BY created_at ASC", [$pl['sub'], $pl['sub']])->fetchAll();
    foreach ($rows as &$r) {
        $r['stats'] = boutique_quick_stats($r['id']);
    }
    ok($rows);
}

function boutique_quick_stats($boutiqueId) {
    $cmd = (int)q("SELECT COUNT(*) c FROM orders WHERE boutique_id=?", [$boutiqueId])->fetch()['c'];
    $enAttente = (int)q("SELECT COUNT(*) c FROM orders WHERE boutique_id=? AND status='pending'", [$boutiqueId])->fetch()['c'];
    $ca = (float)q("SELECT COALESCE(SUM(total),0) s FROM orders WHERE boutique_id=? AND status IN ".ENCAISSE_STATUSES, [$boutiqueId])->fetch()['s'];
    return ['commandes'=>$cmd, 'en_attente'=>$enAttente, 'ca_encaisse'=>$ca];
}

function boutiques_create($pl) {
    $b = body();
    $name = trim($b['name'] ?? '');
    $city = trim($b['city'] ?? '');
    $country = trim($b['country'] ?? '');
    if ($name === '') fail('Le nom de la boutique est requis');
    if ($city === '') fail('La ville de la boutique est requise');
    if ($country === '') fail('Le pays de la boutique est requis');
    $user = q("SELECT plan FROM users WHERE id=?", [$pl['sub']])->fetch();
    $limit = PLANS[$user['plan']]['boutique_limit'] ?? 1;
    $count = (int)q("SELECT COUNT(*) c FROM boutiques WHERE owner_user_id=?", [$pl['sub']])->fetch()['c'];
    if ($count >= $limit) {
        fail('Limite de '.$limit.' boutique(s) atteinte pour votre plan '.PLANS[$user['plan']]['name'].'. Passez a un plan superieur pour en creer davantage.', 403);
    }
    $slug = unique_boutique_slug(slugify($name));
    $id = uid();
    // Devise deduite du pays choisi (modifiable ensuite dans Parametres > General).
    q("INSERT INTO boutiques (id,owner_user_id,slug,name,city,country,currency) VALUES (?,?,?,?,?,?,?)", [$id, $pl['sub'], $slug, $name, $city, $country, currency_for_country($country)]);
    // Compte caisse par defaut, pour que le Livre de Compte ne soit pas vide
    // des la creation (l'utilisateur peut le renommer/en ajouter d'autres).
    q("INSERT INTO accounts (id,boutique_id,name,type) VALUES (?,?,?,?)", [uid(), $id, 'Caisse', 'caisse']);
    q("INSERT INTO abandoned_settings (boutique_id) VALUES (?)", [$id]);
    log_activity($id, 'Boutique creee', $pl['sub']);
    $row = q("SELECT * FROM boutiques WHERE id=?", [$id])->fetch();
    ok($row, 'Boutique creee', 201);
}

function boutiques_update($pl) {
    $b = body();
    $id = $b['id'] ?? '';
    $row = require_boutique_admin($id, $pl['sub']);
    $name = trim($b['name'] ?? $row['name']);
    $codEnabled = isset($b['cod_enabled']) ? (int)!!$b['cod_enabled'] : $row['cod_enabled'];
    $currency = trim($b['currency'] ?? $row['currency']);
    if ($currency !== $row['currency'] && !valid_currency($currency)) fail('Devise invalide');
    $deliveryFee = isset($b['default_delivery_fee']) && $b['default_delivery_fee'] !== ''
        ? max(0, (float)$b['default_delivery_fee']) : $row['default_delivery_fee'];
    $shippingFee = isset($b['default_shipping_fee']) && $b['default_shipping_fee'] !== ''
        ? max(0, (float)$b['default_shipping_fee']) : $row['default_shipping_fee'];
    $shippingFeeOther = isset($b['default_shipping_fee_other_country']) && $b['default_shipping_fee_other_country'] !== ''
        ? max(0, (float)$b['default_shipping_fee_other_country']) : $row['default_shipping_fee_other_country'];
    $description = trim($b['description'] ?? $row['description']);
    $logoUrl = trim($b['logo_url'] ?? $row['logo_url']);
    $notifyOrderEmail = isset($b['notify_order_email']) ? (int)!!$b['notify_order_email'] : $row['notify_order_email'];
    $notifyEmail = trim($b['notify_email'] ?? $row['notify_email']);
    $stockAlertEnabled = isset($b['stock_alert_enabled']) ? (int)!!$b['stock_alert_enabled'] : $row['stock_alert_enabled'];
    $publicListed = isset($b['public_listed']) ? (int)!!$b['public_listed'] : $row['public_listed'];
    $category = trim($b['category'] ?? $row['category']);
    $city = trim($b['city'] ?? $row['city']);
    $country = trim($b['country'] ?? $row['country']);
    q("UPDATE boutiques SET name=?, cod_enabled=?, currency=?, default_delivery_fee=?, default_shipping_fee=?, default_shipping_fee_other_country=?, description=?, logo_url=?,
       notify_order_email=?, notify_email=?, stock_alert_enabled=?, public_listed=?, category=?, city=?, country=? WHERE id=?",
      [$name, $codEnabled, $currency, $deliveryFee, $shippingFee, $shippingFeeOther, $description, $logoUrl, $notifyOrderEmail, $notifyEmail,
       $stockAlertEnabled, $publicListed, $category, $city, $country, $id]);
    ok(q("SELECT * FROM boutiques WHERE id=?", [$id])->fetch(), 'Boutique mise a jour');
}

function boutiques_get($pl) {
    $row = require_boutique_owned($_GET['id'] ?? '', $pl['sub']);
    $row['stats'] = boutique_quick_stats($row['id']);
    ok($row);
}

function boutiques_grade($pl) {
    $row = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $total = (float)q("SELECT COALESCE(SUM(total),0) s FROM orders WHERE boutique_id=? AND status IN ".ENCAISSE_STATUSES, [$row['id']])->fetch()['s'];
    ok(compute_grade($total));
}

// ============================================================
// PRODUITS — catalogue, variantes, stock, fournisseurs
// ============================================================
function route_products($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'products');
    switch ($action) {
        case 'list':   products_list($pl); break;
        case 'get':    products_get($pl); break;
        case 'create': products_create($pl); break;
        case 'update': products_update($pl); break;
        case 'delete': products_delete($pl); break;
        case 'stock_adjust': products_stock_adjust($pl); break;
        case 'image_add':        product_image_add($pl); break;
        case 'image_delete':     product_image_delete($pl); break;
        case 'image_set_primary':product_image_set_primary($pl); break;
        case 'image_reorder':    product_image_reorder($pl); break;
        case 'suppliers_list':   suppliers_list($pl); break;
        case 'supplier_create':  supplier_create($pl); break;
        case 'supplier_update':  supplier_update($pl); break;
        case 'supplier_delete':  supplier_delete($pl); break;
        case 'supplier_orders_list':  supplier_orders_list($pl); break;
        case 'supplier_order_create': supplier_order_create($pl); break;
        case 'supplier_order_update_status': supplier_order_update_status($pl); break;
        case 'categories_list':  categories_list($pl); break;
        case 'category_create':  category_create($pl); break;
        case 'category_update':  category_update($pl); break;
        case 'category_delete':  category_delete($pl); break;
        case 'digital_codes_list':   digital_codes_list($pl); break;
        case 'digital_codes_add':    digital_codes_add($pl); break;
        case 'digital_codes_delete': digital_codes_delete($pl); break;
        default: fail('Action inconnue', 404);
    }
}

function categories_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id=c.id) AS product_count
          FROM product_categories c WHERE c.boutique_id=? ORDER BY c.name", [$bt['id']])->fetchAll());
}
function category_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom de la categorie est requis');
    $id = uid();
    q("INSERT INTO product_categories (id,boutique_id,name) VALUES (?,?,?)", [$id, $bt['id'], $name]);
    ok(q("SELECT * FROM product_categories WHERE id=?", [$id])->fetch(), 'Categorie creee', 201);
}
function category_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM product_categories WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Categorie introuvable', 404);
    return $row;
}
function category_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $cat = category_owned($b['id'] ?? '', $bt['id']);
    $name = trim($b['name'] ?? $cat['name']);
    if ($name === '') fail('Le nom de la categorie est requis');
    q("UPDATE product_categories SET name=? WHERE id=?", [$name, $cat['id']]);
    ok(q("SELECT * FROM product_categories WHERE id=?", [$cat['id']])->fetch(), 'Categorie mise a jour');
}
function category_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $cat = category_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE products SET category_id=NULL WHERE category_id=?", [$cat['id']]);
    q("DELETE FROM product_categories WHERE id=?", [$cat['id']]);
    ok(null, 'Categorie supprimee');
}

function products_list($pl) {
    $row = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    // image_url contient la photo en base64 (potentiellement plusieurs
    // centaines de Ko) - jamais affichee dans ce tableau, donc jamais
    // transferee ici (elle alourdissait chaque page qui liste les produits,
    // meme celles qui ne montrent qu'un menu deroulant sans photo). Elle
    // reste disponible via products_get() pour la fiche d'un seul produit.
    $rows = q("SELECT p.id,p.boutique_id,p.name,p.description,p.price,p.compare_at_price,p.cost_price,p.stock_qty,p.status,
               p.sku,p.barcode,p.slug,p.track_inventory,p.allow_backorder,p.is_physical,p.is_digital,p.delivery_fee,p.shipping_fee,p.shipping_fee_other_country,p.low_stock_threshold,
               p.options_json,p.created_at,p.category_id, c.name AS category_name,
               (p.image_url IS NOT NULL AND p.image_url<>'') AS has_image
               FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
               WHERE p.boutique_id=? ORDER BY p.created_at DESC", [$row['id']])->fetchAll();
    foreach ($rows as &$p) {
        $p['variants'] = q("SELECT * FROM product_variants WHERE product_id=? ORDER BY name", [$p['id']])->fetchAll();
    }
    ok($rows);
}

function product_owned($id, $boutiqueId) {
    $p = q("SELECT * FROM products WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$p) fail('Produit introuvable', 404);
    return $p;
}

function products_get($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($_GET['id'] ?? '', $bt['id']);
    $p['variants'] = q("SELECT * FROM product_variants WHERE product_id=? ORDER BY name", [$p['id']])->fetchAll();
    // Contrairement a products_list() (qui ne renvoie pas les images pour
    // eviter d'alourdir la liste de tous les produits), la fiche d'un seul
    // produit inclut la galerie complete (donnees base64 incluses).
    $p['images'] = q("SELECT * FROM product_images WHERE product_id=? ORDER BY position", [$p['id']])->fetchAll();
    ok($p);
}

function unique_product_slug($boutiqueId, $base, $excludeId = null) {
    $slug = $base; $i = 1;
    while (true) {
        $sql = "SELECT 1 FROM products WHERE boutique_id=? AND slug=?";
        $params = [$boutiqueId, $slug];
        if ($excludeId) { $sql .= " AND id<>?"; $params[] = $excludeId; }
        if (!q($sql, $params)->fetch()) return $slug;
        $i++; $slug = $base.'-'.$i;
    }
}

// Ne garde que des ids qui appartiennent bien a cette boutique (jamais le
// produit lui-meme) - un id invalide/etranger est silencieusement ignore
// plutot que de faire echouer toute la sauvegarde du produit.
function related_product_ids_json($boutiqueId, $ids, $excludeId) {
    $ids = array_values(array_unique(array_filter((array)$ids)));
    if (!$ids) return null;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $valid = q("SELECT id FROM products WHERE boutique_id=? AND id IN ($placeholders)", array_merge([$boutiqueId], $ids))
             ->fetchAll(PDO::FETCH_COLUMN);
    $valid = array_values(array_filter($valid, fn($id) => $id !== $excludeId));
    return $valid ? json_encode(array_slice($valid, 0, 8), JSON_UNESCAPED_UNICODE) : null;
}

function products_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du produit est requis');
    $id = uid();
    $slugInput = trim($b['slug'] ?? '');
    $slug = unique_product_slug($bt['id'], slugify($slugInput !== '' ? $slugInput : $name));
    $optionsJson = trim($b['options_json'] ?? '');
    if ($optionsJson !== '' && json_decode($optionsJson) === null) $optionsJson = '';
    $relatedJson = related_product_ids_json($bt['id'], $b['related_product_ids'] ?? [], null);
    $categoryId = !empty($b['category_id']) ? category_owned($b['category_id'], $bt['id'])['id'] : null;
    q("INSERT INTO products (id,boutique_id,name,description,price,compare_at_price,cost_price,stock_qty,
       image_url,status,sku,barcode,slug,track_inventory,allow_backorder,is_physical,is_digital,delivery_fee,shipping_fee,shipping_fee_other_country,digital_delivery_content,options_json,low_stock_threshold,related_product_ids,category_id)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [$id, $bt['id'], $name, trim($b['description'] ?? ''), (float)($b['price'] ?? 0),
       isset($b['compare_at_price']) && $b['compare_at_price'] !== '' ? (float)$b['compare_at_price'] : null,
       isset($b['cost_price']) && $b['cost_price'] !== '' ? (float)$b['cost_price'] : null,
       (int)($b['stock_qty'] ?? 0), trim($b['image_url'] ?? ''), $b['status'] ?? 'draft',
       trim($b['sku'] ?? ''), trim($b['barcode'] ?? ''), $slug,
       (int)!!($b['track_inventory'] ?? 1), (int)!!($b['allow_backorder'] ?? 0), (int)!!($b['is_physical'] ?? 1),
       (int)!!($b['is_digital'] ?? 0),
       isset($b['delivery_fee']) && $b['delivery_fee'] !== '' ? (float)$b['delivery_fee'] : null,
       isset($b['shipping_fee']) && $b['shipping_fee'] !== '' ? (float)$b['shipping_fee'] : null,
       isset($b['shipping_fee_other_country']) && $b['shipping_fee_other_country'] !== '' ? (float)$b['shipping_fee_other_country'] : null,
       trim($b['digital_delivery_content'] ?? '') !== '' ? trim($b['digital_delivery_content']) : null,
       $optionsJson !== '' ? $optionsJson : null,
       (int)($b['low_stock_threshold'] ?? 5), $relatedJson, $categoryId]);
    foreach (($b['variants'] ?? []) as $v) {
        if (trim($v['name'] ?? '') === '') continue;
        q("INSERT INTO product_variants (id,product_id,name,price,stock_qty) VALUES (?,?,?,?,?)",
          [uid(), $id, trim($v['name']), isset($v['price']) && $v['price']!=='' ? (float)$v['price'] : null, (int)($v['stock_qty'] ?? 0)]);
    }
    // Garde products.image_url et la galerie product_images en synchro des
    // la creation, pour que la photo posee au meme moment que le produit
    // apparaisse bien comme premiere/principale une fois qu'on rouvre la
    // fiche pour en ajouter d'autres.
    $imageUrl = trim($b['image_url'] ?? '');
    if ($imageUrl !== '') {
        q("INSERT INTO product_images (id,product_id,boutique_id,data,position,is_primary) VALUES (?,?,?,?,0,1)",
          [uid(), $id, $bt['id'], $imageUrl]);
    }
    // Photos supplementaires choisies avant meme la creation du produit (le
    // formulaire n'a plus besoin d'un aller-retour enregistrer/rouvrir pour
    // en ajouter plusieurs) - meme plafond de 5 que product_image_add().
    $extraImages = array_slice(array_filter((array)($b['extra_images'] ?? []), fn($d) => trim((string)$d) !== ''), 0, 4);
    foreach ($extraImages as $i => $data) {
        q("INSERT INTO product_images (id,product_id,boutique_id,data,position,is_primary) VALUES (?,?,?,?,?,0)",
          [uid(), $id, $bt['id'], $data, $i + 1]);
    }
    // Codes numeriques colles directement a la creation - meme logique que
    // digital_codes_add(), sans le controle de doublons contre l'existant
    // puisqu'il n'y a encore aucun code pour ce produit tout neuf.
    $codesRaw = (string)($b['codes'] ?? '');
    $codeLines = array_values(array_unique(array_filter(array_map('trim', explode("\n", $codesRaw)))));
    foreach ($codeLines as $code) {
        q("INSERT INTO product_digital_codes (id,product_id,code) VALUES (?,?,?)", [uid(), $id, $code]);
    }
    log_activity($bt['id'], 'Produit ajoute: '.$name, $pl['sub']);
    ok(q("SELECT * FROM products WHERE id=?", [$id])->fetch(), 'Produit cree', 201);
}

function products_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['id'] ?? '', $bt['id']);
    $name = trim($b['name'] ?? $p['name']);
    $slugInput = trim($b['slug'] ?? '');
    $slug = $slugInput !== ''
        ? unique_product_slug($bt['id'], slugify($slugInput), $p['id'])
        : ($p['slug'] ?: unique_product_slug($bt['id'], slugify($name), $p['id']));
    $optionsJson = null;
    if (array_key_exists('options_json', $b)) {
        $optionsJson = trim($b['options_json'] ?? '');
        if ($optionsJson !== '' && json_decode($optionsJson) === null) $optionsJson = '';
        $optionsJson = $optionsJson !== '' ? $optionsJson : null;
    } else {
        $optionsJson = $p['options_json'];
    }
    $relatedJson = array_key_exists('related_product_ids', $b)
        ? related_product_ids_json($bt['id'], $b['related_product_ids'], $p['id'])
        : $p['related_product_ids'];
    $categoryId = $p['category_id'];
    if (array_key_exists('category_id', $b)) {
        $categoryId = !empty($b['category_id']) ? category_owned($b['category_id'], $bt['id'])['id'] : null;
    }
    q("UPDATE products SET name=?, description=?, price=?, compare_at_price=?, cost_price=?, stock_qty=?,
       image_url=?, status=?, sku=?, barcode=?, slug=?, track_inventory=?, allow_backorder=?, is_physical=?, is_digital=?, delivery_fee=?, shipping_fee=?, shipping_fee_other_country=?, digital_delivery_content=?, options_json=?, low_stock_threshold=?, related_product_ids=?, category_id=?
       WHERE id=?",
      [$name, trim($b['description'] ?? $p['description']), (float)($b['price'] ?? $p['price']),
       isset($b['compare_at_price']) && $b['compare_at_price'] !== '' ? (float)$b['compare_at_price'] : $p['compare_at_price'],
       isset($b['cost_price']) && $b['cost_price'] !== '' ? (float)$b['cost_price'] : $p['cost_price'],
       (int)($b['stock_qty'] ?? $p['stock_qty']), trim($b['image_url'] ?? $p['image_url']),
       $b['status'] ?? $p['status'], trim($b['sku'] ?? $p['sku']), trim($b['barcode'] ?? $p['barcode']), $slug,
       isset($b['track_inventory']) ? (int)!!$b['track_inventory'] : $p['track_inventory'],
       isset($b['allow_backorder']) ? (int)!!$b['allow_backorder'] : $p['allow_backorder'],
       isset($b['is_physical']) ? (int)!!$b['is_physical'] : $p['is_physical'],
       isset($b['is_digital']) ? (int)!!$b['is_digital'] : $p['is_digital'],
       isset($b['delivery_fee']) && $b['delivery_fee'] !== '' ? (float)$b['delivery_fee'] : null,
       isset($b['shipping_fee']) && $b['shipping_fee'] !== '' ? (float)$b['shipping_fee'] : null,
       isset($b['shipping_fee_other_country']) && $b['shipping_fee_other_country'] !== '' ? (float)$b['shipping_fee_other_country'] : null,
       array_key_exists('digital_delivery_content', $b) ? (trim($b['digital_delivery_content']) !== '' ? trim($b['digital_delivery_content']) : null) : $p['digital_delivery_content'],
       $optionsJson, isset($b['low_stock_threshold']) && $b['low_stock_threshold'] !== '' ? (int)$b['low_stock_threshold'] : $p['low_stock_threshold'],
       $relatedJson, $categoryId, $p['id']]);
    // Remplacement complet des variantes si le champ est fourni (le
    // generateur d'options cote tableau de bord envoie toujours la liste
    // complete a jour, y compris les variantes inchangees).
    if (array_key_exists('variants', $b)) {
        q("DELETE FROM product_variants WHERE product_id=?", [$p['id']]);
        foreach (($b['variants'] ?? []) as $v) {
            if (trim($v['name'] ?? '') === '') continue;
            q("INSERT INTO product_variants (id,product_id,name,price,stock_qty) VALUES (?,?,?,?,?)",
              [uid(), $p['id'], trim($v['name']), isset($v['price']) && $v['price']!=='' ? (float)$v['price'] : null, (int)($v['stock_qty'] ?? 0)]);
        }
    }
    ok(q("SELECT * FROM products WHERE id=?", [$p['id']])->fetch(), 'Produit mis a jour');
}

// Galerie photos : chaque produit peut avoir plusieurs images. La premiere
// ajoutee devient automatiquement l'image principale (is_primary=1) et
// products.image_url est maintenu en synchro avec elle, pour que tout le
// code deja ecrit (liste produits, vitrine, panier) continue de fonctionner
// sans modification.
function product_image_add($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['product_id'] ?? '', $bt['id']);
    $data = trim($b['data'] ?? '');
    if ($data === '') fail('Image manquante');
    $count = (int)q("SELECT COUNT(*) c FROM product_images WHERE product_id=?", [$p['id']])->fetch()['c'];
    if ($count >= 5) fail('5 images maximum par produit', 400);
    $isPrimary = $count === 0 ? 1 : 0;
    $id = uid();
    q("INSERT INTO product_images (id,product_id,boutique_id,data,position,is_primary) VALUES (?,?,?,?,?,?)",
      [$id, $p['id'], $bt['id'], $data, $count, $isPrimary]);
    if ($isPrimary) q("UPDATE products SET image_url=? WHERE id=?", [$data, $p['id']]);
    ok(['id'=>$id, 'is_primary'=>$isPrimary], 'Image ajoutee', 201);
}
function product_image_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM product_images WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Image introuvable', 404);
    return $row;
}
function product_image_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $img = product_image_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM product_images WHERE id=?", [$img['id']]);
    if ($img['is_primary']) {
        $next = q("SELECT * FROM product_images WHERE product_id=? ORDER BY position LIMIT 1", [$img['product_id']])->fetch();
        if ($next) {
            q("UPDATE product_images SET is_primary=1 WHERE id=?", [$next['id']]);
            q("UPDATE products SET image_url=? WHERE id=?", [$next['data'], $img['product_id']]);
        } else {
            q("UPDATE products SET image_url='' WHERE id=?", [$img['product_id']]);
        }
    }
    ok(null, 'Image supprimee');
}
// Reordonne la galerie apres un glisser-deposer cote tableau de bord -
// la premiere image de la liste devient automatiquement la principale
// (meme logique que product_image_set_primary(), juste declenchee par
// l'ordre plutot que par un clic sur "Definir").
function product_image_reorder($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $productId = $b['product_id'] ?? '';
    $orderedIds = $b['image_ids'] ?? [];
    if (!is_array($orderedIds) || !count($orderedIds)) fail('Ordre des images invalide');
    // Chaque id doit reellement appartenir a ce produit/cette boutique -
    // sinon un id etranger glisse dans la requete pourrait deplacer une
    // image d'un autre produit.
    $existing = q("SELECT id FROM product_images WHERE product_id=? AND boutique_id=?", [$productId, $bt['id']])->fetchAll(PDO::FETCH_COLUMN);
    if (count($orderedIds) !== count($existing) || array_diff($orderedIds, $existing)) fail('Ordre des images invalide');
    foreach ($orderedIds as $i => $imgId) {
        q("UPDATE product_images SET position=?, is_primary=? WHERE id=?", [$i, $i === 0 ? 1 : 0, $imgId]);
    }
    $primary = q("SELECT data FROM product_images WHERE id=?", [$orderedIds[0]])->fetch();
    q("UPDATE products SET image_url=? WHERE id=?", [$primary['data'], $productId]);
    ok(null, 'Ordre des photos mis a jour');
}
function product_image_set_primary($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $img = product_image_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE product_images SET is_primary=0 WHERE product_id=?", [$img['product_id']]);
    q("UPDATE product_images SET is_primary=1 WHERE id=?", [$img['id']]);
    q("UPDATE products SET image_url=? WHERE id=?", [$img['data'], $img['product_id']]);
    ok(null, 'Image principale mise a jour');
}

function products_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM product_variants WHERE product_id=?", [$p['id']]);
    q("DELETE FROM product_images WHERE product_id=?", [$p['id']]);
    q("DELETE FROM product_digital_codes WHERE product_id=?", [$p['id']]);
    q("DELETE FROM product_reviews WHERE product_id=?", [$p['id']]);
    q("DELETE FROM products WHERE id=?", [$p['id']]);
    ok(null, 'Produit supprime');
}

function products_stock_adjust($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['id'] ?? '', $bt['id']);
    $delta = (int)($b['delta'] ?? 0);
    q("UPDATE products SET stock_qty = stock_qty + ? WHERE id=?", [$delta, $p['id']]);
    ok(q("SELECT * FROM products WHERE id=?", [$p['id']])->fetch(), 'Stock ajuste');
}

// ============================================================
// CODES NUMERIQUES A USAGE UNIQUE (licences, codes cadeaux...)
// Alternative a digital_delivery_content pour un produit numerique quand
// chaque acheteur doit recevoir un code DIFFERENT plutot que le meme
// contenu partage - voir maybe_send_digital_delivery() qui pioche dedans.
// ============================================================
function digital_codes_list($pl) {
    $b = body() ?: [];
    $boutiqueId = $_GET['boutique_id'] ?? ($b['boutique_id'] ?? '');
    $productId = $_GET['product_id'] ?? ($b['product_id'] ?? '');
    $bt = require_boutique_owned($boutiqueId, $pl['sub']);
    $p = product_owned($productId, $bt['id']);
    $rows = q("SELECT id, code, status, used_at, created_at FROM product_digital_codes
               WHERE product_id=? ORDER BY (status='available') DESC, created_at DESC LIMIT 500", [$p['id']])->fetchAll();
    $available = q("SELECT COUNT(*) c FROM product_digital_codes WHERE product_id=? AND status='available'", [$p['id']])->fetch()['c'];
    ok(['codes' => $rows, 'available_count' => (int)$available]);
}
// Un code par ligne - les lignes vides sont ignorees, les doublons exacts
// deja presents dans le pool de ce produit ne sont pas rajoutes (evite les
// codes en double si le marchand colle deux fois la meme liste par erreur).
function digital_codes_add($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['product_id'] ?? '', $bt['id']);
    $raw = (string)($b['codes'] ?? '');
    $lines = array_values(array_unique(array_filter(array_map('trim', explode("\n", $raw)))));
    if (!$lines) fail('Le code est requis');
    $existing = q("SELECT code FROM product_digital_codes WHERE product_id=?", [$p['id']])->fetchAll(PDO::FETCH_COLUMN);
    $existingSet = array_flip($existing);
    $added = 0;
    foreach ($lines as $code) {
        if (isset($existingSet[$code])) continue;
        q("INSERT INTO product_digital_codes (id,product_id,code) VALUES (?,?,?)", [uid(), $p['id'], $code]);
        $existingSet[$code] = true;
        $added++;
    }
    ok(['added' => $added], $added.' code(s) ajoute(s)');
}
function digital_codes_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['product_id'] ?? '', $bt['id']);
    // Un code deja utilise reste rattache a sa commande (support client) -
    // seul un code encore disponible peut etre retire du pool.
    q("DELETE FROM product_digital_codes WHERE id=? AND product_id=? AND status='available'", [$b['id'] ?? '', $p['id']]);
    ok(null, 'Code supprime');
}

function suppliers_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM suppliers WHERE boutique_id=? ORDER BY name", [$bt['id']])->fetchAll());
}
function supplier_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du fournisseur est requis');
    $id = uid();
    q("INSERT INTO suppliers (id,boutique_id,name,phone,notes) VALUES (?,?,?,?,?)",
      [$id, $bt['id'], $name, trim($b['phone'] ?? ''), trim($b['notes'] ?? '')]);
    ok(q("SELECT * FROM suppliers WHERE id=?", [$id])->fetch(), 'Fournisseur ajoute', 201);
}
function supplier_owned($id, $boutiqueId) {
    $s = q("SELECT * FROM suppliers WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$s) fail('Fournisseur introuvable', 404);
    return $s;
}
function supplier_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $s = supplier_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE suppliers SET name=?, phone=?, notes=? WHERE id=?",
      [trim($b['name'] ?? $s['name']), trim($b['phone'] ?? $s['phone']), trim($b['notes'] ?? $s['notes']), $s['id']]);
    ok(q("SELECT * FROM suppliers WHERE id=?", [$s['id']])->fetch(), 'Fournisseur mis a jour');
}
function supplier_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $s = supplier_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM suppliers WHERE id=?", [$s['id']]);
    ok(null, 'Fournisseur supprime');
}

function supplier_orders_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $rows = q("SELECT so.*, s.name AS supplier_name, p.name AS product_name
               FROM supplier_orders so
               LEFT JOIN suppliers s ON s.id = so.supplier_id
               LEFT JOIN products p ON p.id = so.product_id
               WHERE so.boutique_id=? ORDER BY so.created_at DESC", [$bt['id']])->fetchAll();
    ok($rows);
}
function supplier_order_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    supplier_owned($b['supplier_id'] ?? '', $bt['id']);
    $id = uid();
    q("INSERT INTO supplier_orders (id,boutique_id,supplier_id,product_id,qty,unit_cost,status)
       VALUES (?,?,?,?,?,?,?)",
      [$id, $bt['id'], $b['supplier_id'], $b['product_id'] ?? null, (int)($b['qty'] ?? 1),
       (float)($b['unit_cost'] ?? 0), 'pending']);
    ok(q("SELECT * FROM supplier_orders WHERE id=?", [$id])->fetch(), 'Commande fournisseur creee', 201);
}
function supplier_order_update_status($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $so = q("SELECT * FROM supplier_orders WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']])->fetch();
    if (!$so) fail('Commande fournisseur introuvable', 404);
    $status = $b['status'] ?? $so['status'];
    q("UPDATE supplier_orders SET status=? WHERE id=?", [$status, $so['id']]);
    // Une commande fournisseur marquee "received" reapprovisionne le stock.
    if ($status === 'received' && $so['status'] !== 'received' && $so['product_id']) {
        q("UPDATE products SET stock_qty = stock_qty + ? WHERE id=?", [$so['qty'], $so['product_id']]);
    }
    ok(q("SELECT * FROM supplier_orders WHERE id=?", [$so['id']])->fetch(), 'Statut mis a jour');
}

// ============================================================
// VITRINE PUBLIQUE (module "shop") — aucune authentification, utilisee par
// store/index.html : catalogue, fiche produit, commande COD, capture des
// paniers abandonnes, newsletter, formulaire de contact.
// ============================================================
function route_shop($action) {
    switch ($action) {
        case 'boutique':         shop_boutique(); break;
        case 'rates':            shop_rates(); break;
        case 'products':         shop_products(); break;
        case 'product':          shop_product(); break;
        case 'checkout':         shop_checkout(); break;
        case 'track_visit':      shop_track_visit(); break;
        case 'track_abandoned':  shop_track_abandoned(); break;
        case 'newsletter':       shop_newsletter(); break;
        case 'contact_message':  shop_contact_message(); break;
        case 'validate_promo':   shop_validate_promo(); break;
        case 'active_promos':    shop_active_promos(); break;
        case 'categories':       shop_categories(); break;
        case 'promo_for_phone':  shop_promo_for_phone(); break;
        case 'track_order':      shop_track_order(); break;
        case 'submit_dispute':   shop_submit_dispute(); break;
        case 'orders_for_phone': shop_orders_for_phone(); break;
        case 'reviews':          shop_reviews(); break;
        case 'review_add':       shop_review_add(); break;
        default: fail('Action inconnue', 404);
    }
}

// ============================================================
// ANNUAIRE PUBLIC (directory/index.html) - navigation/recherche parmi les
// boutiques qui ont explicitement choisi d'y apparaitre (public_listed=1,
// jamais automatique - voir boutiques_update()). Aucune authentification :
// c'est une vitrine de decouverte, comme store/index.html mais a l'echelle
// de toute la plateforme plutot que d'une seule boutique.
// ============================================================
function route_directory($action) {
    switch ($action) {
        case 'boutiques': directory_boutiques(); break;
        case 'products':  directory_products(); break;
        case 'filters':   directory_filters(); break;
        default: fail('Action inconnue', 404);
    }
}
function directory_boutiques() {
    rate_limit_check('directory_boutiques', 60, 300);
    $q = trim($_GET['q'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $city = trim($_GET['city'] ?? '');
    $country = trim($_GET['country'] ?? '');
    // Note moyenne calculee a la volee a partir des avis produits deja
    // approuves par le marchand (product_reviews.status='approved') -
    // aucune nouvelle table, juste une agregation par boutique.
    $sql = "SELECT b.slug, b.name, b.description, b.logo_url, b.currency, b.category, b.city, b.country,
              (SELECT ROUND(AVG(r.rating)::numeric, 1) FROM product_reviews r WHERE r.boutique_id=b.id AND r.status='approved') AS avg_rating,
              (SELECT COUNT(*) FROM product_reviews r WHERE r.boutique_id=b.id AND r.status='approved') AS review_count
            FROM boutiques b JOIN users u ON u.id=b.owner_user_id
            WHERE b.status='active' AND b.public_listed=1 AND (u.plan_valid_until IS NULL OR u.plan_valid_until >= NOW())";
    $params = [];
    if ($q !== '') { $sql .= " AND b.name ILIKE ?"; $params[] = '%'.$q.'%'; }
    if ($category !== '') { $sql .= " AND b.category=?"; $params[] = $category; }
    if ($city !== '') { $sql .= " AND b.city ILIKE ?"; $params[] = '%'.$city.'%'; }
    if ($country !== '') { $sql .= " AND b.country=?"; $params[] = $country; }
    $sql .= " ORDER BY b.created_at DESC LIMIT 60";
    ok(q($sql, $params)->fetchAll());
}
// Categories et villes distinctes parmi les boutiques listees - alimente
// les listes deroulantes de filtre de l'annuaire (evite de proposer un
// filtre sur une valeur qu'aucune boutique n'utilise).
function directory_filters() {
    rate_limit_check('directory_filters', 60, 300);
    $categories = q("SELECT DISTINCT category FROM boutiques WHERE status='active' AND public_listed=1 AND category IS NOT NULL AND category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    $cities = q("SELECT DISTINCT city FROM boutiques WHERE status='active' AND public_listed=1 AND city IS NOT NULL AND city<>'' ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);
    $countries = q("SELECT DISTINCT country FROM boutiques WHERE status='active' AND public_listed=1 AND country IS NOT NULL AND country<>'' ORDER BY country")->fetchAll(PDO::FETCH_COLUMN);
    ok(['categories' => $categories, 'cities' => $cities, 'countries' => $countries]);
}
// Recherche d'articles a travers TOUTES les boutiques listees publiquement -
// le nom de la boutique est renvoye avec chaque article pour que le client
// sache chez qui il achete avant de cliquer (le lien pointe directement vers
// la fiche produit dans cette boutique).
function directory_products() {
    rate_limit_check('directory_products', 60, 300);
    $q = trim($_GET['q'] ?? '');
    if ($q === '') ok([]);
    $rows = q("SELECT p.id AS product_id, p.name, p.price, p.slug AS product_slug,
                      b.slug AS boutique_slug, b.name AS boutique_name, b.currency
               FROM products p JOIN boutiques b ON b.id = p.boutique_id JOIN users u ON u.id = b.owner_user_id
               WHERE b.status='active' AND b.public_listed=1 AND p.status='active' AND p.name ILIKE ?
                 AND (u.plan_valid_until IS NULL OR u.plan_valid_until >= NOW())
               ORDER BY p.created_at DESC LIMIT 60", ['%'.$q.'%'])->fetchAll();
    ok($rows);
}

// ============================================================
// IMAGES SERVIES DEPUIS UNE URL STABLE (image?action=...) - les photos sont
// stockees en base64 directement en base (jamais comme fichiers a part),
// ce qui les rend inutilisables telles quelles pour un og:image : les
// robots WhatsApp/Facebook qui generisent les apercus de lien exigent une
// vraie URL http(s) a recuperer, pas une data: URI. Cette route decode et
// resert l'image existante sous une URL classique, sans dupliquer le
// stockage.
// ============================================================
function route_image($action) {
    switch ($action) {
        case 'boutique_logo': image_boutique_logo(); break;
        case 'product_photo': image_product_photo(); break;
        default: fail('Action inconnue', 404);
    }
}
function output_data_uri_image($dataUri) {
    if (!$dataUri || !preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#', $dataUri, $m)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Image introuvable';
        exit;
    }
    header('Content-Type: '.$m[1]);
    header('Cache-Control: public, max-age=3600');
    echo base64_decode($m[2]);
    exit;
}
function image_boutique_logo() {
    $slug = trim($_GET['slug'] ?? '');
    $bt = q("SELECT logo_url FROM boutiques WHERE slug=? AND status='active'", [$slug])->fetch();
    output_data_uri_image($bt['logo_url'] ?? null);
}
function image_product_photo() {
    $slug = trim($_GET['slug'] ?? '');
    $productSlug = trim($_GET['p'] ?? '');
    $row = q("SELECT p.image_url FROM products p JOIN boutiques b ON b.id=p.boutique_id
              WHERE b.slug=? AND p.slug=? AND b.status='active' AND p.status='active'", [$slug, $productSlug])->fetch();
    output_data_uri_image($row['image_url'] ?? null);
}

// ============================================================
// PAGES D'APERCU POUR LE PARTAGE (preview?action=...) - le frontend est un
// site 100% statique dont le contenu se charge en JavaScript ; les robots
// qui generent les apercus de lien WhatsApp/Facebook ne l'executent
// generalement pas et ne verraient donc que le HTML generique, identique
// pour toutes les boutiques. Cette route, servie par le backend PHP, genere
// a la volee une page HTML minimale avec les vraies balises Open Graph
// (nom, description, image) puis redirige immediatement le visiteur humain
// vers la vraie page interactive - le robot lit les balises sans suivre la
// redirection, l'humain ne voit quasiment rien (redirection instantanee).
// C'est cette URL de "preview", pas le lien direct, qui doit etre partagee.
// ============================================================
function route_preview($action) {
    switch ($action) {
        case 'shop':    preview_shop(); break;
        case 'product': preview_product(); break;
        default: fail('Action inconnue', 404);
    }
}
function preview_not_found() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Introuvable';
    exit;
}
function preview_html($title, $description, $imageUrl, $redirectUrl) {
    header('Content-Type: text/html; charset=utf-8');
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    $imageAttr = $imageUrl ? '<meta property="og:image" content="'.htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8').'">' : '';
    $redirectUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        .'<title>'.$title.'</title>'
        .'<meta property="og:title" content="'.$title.'">'
        .'<meta property="og:description" content="'.$description.'">'
        .$imageAttr
        .'<meta property="og:type" content="website">'
        .'<meta name="twitter:card" content="summary_large_image">'
        .'<meta http-equiv="refresh" content="0; url='.$redirectUrl.'">'
        .'<style>body{font-family:Arial,sans-serif;padding:40px;text-align:center;color:#0f172a}a{color:#ea580c;font-weight:700}</style>'
        .'</head><body>'
        .'<p>Redirection vers '.$title.'…</p>'
        .'<p><a href="'.$redirectUrl.'">Cliquez ici si la redirection ne fonctionne pas</a></p>'
        .'<script>window.location.replace('.json_encode($redirectUrl).');</script>'
        .'</body></html>';
    exit;
}
// Render/Cloudflare terminent le HTTPS a la frontiere et transmettent la
// requete en HTTP simple a ce conteneur - $_SERVER['HTTPS'] est donc
// toujours vide ici meme quand le vrai visiteur est en https, d'ou
// l'en-tete standard X-Forwarded-Proto (pose par le proxy) verifie en
// priorite. Sans ce correctif, og:image pointait vers un lien http:// que
// WhatsApp/Facebook refusent d'afficher (image absente de l'apercu).
function preview_self_base() {
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $isHttps = strtolower($forwardedProto) === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $isHttps ? 'https' : 'http';
    return $scheme.'://'.$_SERVER['HTTP_HOST'];
}
function preview_shop() {
    $slug = trim($_GET['slug'] ?? '');
    $bt = q("SELECT name, description, logo_url FROM boutiques WHERE slug=? AND status='active'", [$slug])->fetch();
    if (!$bt) preview_not_found();
    $title = $bt['name'].' — MYBOUTIK';
    $desc = trim($bt['description'] ?? '') !== '' ? $bt['description'] : 'Decouvrez '.$bt['name'].' sur MYBOUTIK - paiement a la livraison.';
    $image = $bt['logo_url'] ? preview_self_base().'/image?action=boutique_logo&slug='.urlencode($slug) : null;
    $redirect = FRONTEND_BASE_URL.'/store/index.html?b='.urlencode($slug);
    preview_html($title, $desc, $image, $redirect);
}
function preview_product() {
    $slug = trim($_GET['slug'] ?? '');
    $productSlug = trim($_GET['p'] ?? '');
    $row = q("SELECT p.name, p.description, p.price, p.image_url, b.name AS boutique_name, b.currency
              FROM products p JOIN boutiques b ON b.id=p.boutique_id
              WHERE b.slug=? AND p.slug=? AND b.status='active' AND p.status='active'", [$slug, $productSlug])->fetch();
    if (!$row) preview_not_found();
    $title = $row['name'].' — '.$row['boutique_name'];
    $price = money_fmt($row['price'], $row['currency']).' '.($row['currency'] ?: 'XOF');
    $desc = trim($row['description'] ?? '') !== '' ? $row['description'] : ($row['name'].' a '.$price.' chez '.$row['boutique_name'].' sur MYBOUTIK.');
    $image = $row['image_url'] ? preview_self_base().'/image?action=product_photo&slug='.urlencode($slug).'&p='.urlencode($productSlug) : null;
    $redirect = FRONTEND_BASE_URL.'/store/index.html?b='.urlencode($slug).'&p='.urlencode($productSlug);
    preview_html($title, $desc, $image, $redirect);
}

// Boutique visible publiquement (vitrine, annuaire) : active ET abonnement
// du proprietaire toujours valide. Un marchand qui ne paie plus n'est plus
// "fonctionnel" (le dashboard lui-meme est verrouille, voir
// assert_owner_plan_active()) - sa vitrine ne doit donc plus accepter de
// commandes ni etre trouvable, sans etre supprimee ni desinscrite de
// l'annuaire (public_listed reste tel quel) : des qu'il se reabonne, elle
// redevient visible automatiquement, aucune reactivation manuelle requise.
function public_boutique_by_slug($slug) {
    $row = q("SELECT b.id,b.slug,b.name,b.description,b.logo_url,b.currency,b.cod_enabled,b.default_delivery_fee,b.default_shipping_fee,b.default_shipping_fee_other_country,b.status,b.city,b.country, u.plan_valid_until
              FROM boutiques b JOIN users u ON u.id=b.owner_user_id WHERE b.slug=?", [$slug])->fetch();
    if (!$row || $row['status'] !== 'active') fail('Boutique introuvable', 404);
    if ($row['plan_valid_until'] !== null && strtotime($row['plan_valid_until']) < time()) fail('Boutique introuvable', 404);
    unset($row['plan_valid_until']);
    return $row;
}

function shop_boutique() {
    ok(public_boutique_by_slug($_GET['slug'] ?? ''));
}
// Taux INDICATIFS pour la vitrine : combien d'unites de chaque devise pour 1
// unite de la devise de la boutique (l'acheteur voit "≈ X" sous le prix
// officiel, jamais utilise pour facturer). Vide si aucun taux n'est connu
// pour la devise de la boutique - la vitrine masque alors le choix de devise.
function shop_rates() {
    rate_limit_check('shop_rates', 60, 300);
    $bt = public_boutique_by_slug($_GET['slug'] ?? '');
    $cur = $bt['currency'] ?: 'XOF';
    $rates = currency_rates();
    $out = [];
    if (isset($rates[$cur])) {
        foreach (array_unique(array_values(COUNTRY_CURRENCY)) as $code) {
            if ($code === $cur || !isset($rates[$code]) || $rates[$code] <= 0) continue;
            $out[$code] = round($rates[$cur] / $rates[$code], 10);
        }
    }
    ok(['currency' => $cur, 'rates' => $out]);
}

// Promotion "produit" active (independante des codes promo - visible
// directement sur le prix affiche, rien a saisir cote client). Au plus une
// promotion appliquee par produit ; en cas de chevauchement, la plus
// recemment creee gagne plutot que de cumuler les reductions.
function active_product_promotion($productId) {
    return q("SELECT pp.type, pp.value FROM product_promotions pp
              JOIN product_promotion_items ppi ON ppi.promotion_id = pp.id
              WHERE ppi.product_id=? AND pp.active=1
              AND (pp.starts_at IS NULL OR pp.starts_at <= NOW())
              AND (pp.expires_at IS NULL OR pp.expires_at > NOW())
              ORDER BY pp.created_at DESC LIMIT 1", [$productId])->fetch();
}
function effective_unit_price($productId, $basePrice) {
    $promo = active_product_promotion($productId);
    if (!$promo) return (float)$basePrice;
    $discount = $promo['type'] === 'amount' ? (float)$promo['value'] : round($basePrice * ((float)$promo['value'] / 100), 2);
    return max(0, (float)$basePrice - min($discount, (float)$basePrice));
}
// Applique la promotion (si active) directement sur le tableau produit tel
// que renvoye a la vitrine : compare_at_price devient le prix catalogue
// d'origine, price devient le prix reduit - reutilise donc l'affichage
// "prix barre" deja code cote store/index.html sans rien y changer la-bas.
// Ne touche jamais products.price en base - seulement ce qui est renvoye
// ici, le catalogue du marchand garde son vrai prix.
function apply_active_promotion(&$p) {
    $effective = effective_unit_price($p['id'], (float)$p['price']);
    if ($effective < (float)$p['price']) {
        $p['compare_at_price'] = $p['price'];
        $p['price'] = $effective;
    }
}

// Categories visibles publiquement (uniquement celles ayant au moins un
// produit actif, pour ne pas afficher un filtre menant a une liste vide).
function shop_categories() {
    $bt = public_boutique_by_slug($_GET['slug'] ?? '');
    ok(q("SELECT DISTINCT c.id, c.name FROM product_categories c
          JOIN products p ON p.category_id = c.id
          WHERE c.boutique_id=? AND p.status='active' ORDER BY c.name", [$bt['id']])->fetchAll());
}

function shop_products() {
    $bt = public_boutique_by_slug($_GET['slug'] ?? '');
    $sql = "SELECT id,name,description,price,compare_at_price,stock_qty,image_url,slug,track_inventory,allow_backorder,is_physical,is_digital,delivery_fee,shipping_fee,shipping_fee_other_country,options_json,category_id
            FROM products WHERE boutique_id=? AND status='active'";
    $params = [$bt['id']];
    if (!empty($_GET['category_id'])) { $sql .= " AND category_id=?"; $params[] = $_GET['category_id']; }
    $sql .= " ORDER BY created_at DESC";
    $rows = q($sql, $params)->fetchAll();
    foreach ($rows as &$p) {
        $p['variants'] = q("SELECT id,name,price,stock_qty FROM product_variants WHERE product_id=? ORDER BY name", [$p['id']])->fetchAll();
        $p['images'] = q("SELECT id,data FROM product_images WHERE product_id=? ORDER BY position", [$p['id']])->fetchAll();
        apply_active_promotion($p);
    }
    ok($rows);
}

function shop_product() {
    $bt = public_boutique_by_slug($_GET['slug'] ?? '');
    $p = q("SELECT id,name,description,price,compare_at_price,stock_qty,image_url,slug,track_inventory,allow_backorder,is_physical,is_digital,delivery_fee,shipping_fee,shipping_fee_other_country,options_json,related_product_ids
            FROM products WHERE id=? AND boutique_id=? AND status='active'", [$_GET['id'] ?? '', $bt['id']])->fetch();
    if (!$p) fail('Produit introuvable', 404);
    $p['variants'] = q("SELECT id,name,price,stock_qty FROM product_variants WHERE product_id=? ORDER BY name", [$p['id']])->fetchAll();
    $p['images'] = q("SELECT id,data FROM product_images WHERE product_id=? ORDER BY position", [$p['id']])->fetchAll();
    apply_active_promotion($p);
    // Produits associes (upsell) choisis a la main par le marchand sur la
    // fiche produit du tableau de bord - seuls les produits toujours actifs
    // sont proposes a l'achat, un produit retire du catalogue disparait donc
    // silencieusement de cette liste.
    $relatedIds = json_decode($p['related_product_ids'] ?: '[]', true);
    $p['related'] = [];
    if (is_array($relatedIds) && $relatedIds) {
        $placeholders = implode(',', array_fill(0, count($relatedIds), '?'));
        $p['related'] = q("SELECT id,name,slug,price,compare_at_price,image_url
                            FROM products WHERE boutique_id=? AND status='active' AND id IN ($placeholders)",
                           array_merge([$bt['id']], $relatedIds))->fetchAll();
    }
    unset($p['related_product_ids']);
    $ratingRow = q("SELECT COUNT(*) c, COALESCE(AVG(rating),0) a FROM product_reviews WHERE product_id=? AND status='approved'", [$p['id']])->fetch();
    $p['review_count'] = (int)$ratingRow['c'];
    $p['review_avg'] = round((float)$ratingRow['a'], 1);
    ok($p);
}

// $code potentiellement invalide/expire -> null (jamais d'exception ici,
// c'est a l'appelant de decider si c'est bloquant). La casse est ignoree
// (voir l'index UPPER(code) dans route_install()). Un code SANS aucune
// ligne dans promo_code_customers reste ouvert a tous (comportement
// d'origine) ; des qu'au moins un numero lui est associe, seul ce(s)
// numero(s) peuvent l'utiliser - meme si quelqu'un d'autre obtient le code
// par un tiers, $phone absent/non liste le fait echouer ici.
function find_active_promo($boutiqueId, $code, $phone = null) {
    $code = trim($code ?? '');
    if ($code === '') return null;
    $promo = q("SELECT * FROM promo_codes WHERE boutique_id=? AND UPPER(code)=UPPER(?) AND active=1", [$boutiqueId, $code])->fetch();
    if (!$promo) return null;
    if ($promo['expires_at'] && strtotime($promo['expires_at']) < time()) return null;
    if ($promo['max_uses'] !== null && (int)$promo['used_count'] >= (int)$promo['max_uses']) return null;
    $targetCount = (int)q("SELECT COUNT(*) c FROM promo_code_customers WHERE promo_code_id=?", [$promo['id']])->fetch()['c'];
    if ($targetCount > 0) {
        $phone = trim($phone ?? '');
        if ($phone === '') return null;
        if (!q("SELECT 1 FROM promo_code_customers WHERE promo_code_id=? AND phone=?", [$promo['id'], $phone])->fetch()) return null;
    }
    return $promo;
}
function promo_discount_amount($promo, $subtotal) {
    $discount = $promo['type'] === 'amount' ? (float)$promo['value'] : round($subtotal * ((float)$promo['value'] / 100), 2);
    return max(0, min($discount, $subtotal));
}
// Apercu du rabais avant de valider la commande (affiche au checkout, une
// fois que le client a saisi son telephone - necessaire pour verifier un
// code cible sur un client precis) - ne consomme pas le code (used_count
// n'est incremente qu'au checkout reel).
function shop_validate_promo() {
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $subtotal = max(0, (float)($b['subtotal'] ?? 0));
    $promo = find_active_promo($bt['id'], $b['code'] ?? '', $b['phone'] ?? '');
    if (!$promo) fail('Code promo invalide, expire, ou reserve a un autre client', 404);
    ok(['code'=>$promo['code'], 'type'=>$promo['type'], 'value'=>(float)$promo['value'], 'discount'=>promo_discount_amount($promo, $subtotal)]);
}
// Codes promo ouverts a tous (jamais les codes cibles sur un client precis -
// ceux-la restent invisibles, communiques par le marchand lui-meme) -
// affiches en bandeau public sur la vitrine (voir renderShop() cote store).
function shop_active_promos() {
    $bt = public_boutique_by_slug($_GET['slug'] ?? '');
    ok(q("SELECT code, type, value FROM promo_codes pc
          WHERE boutique_id=? AND active=1
          AND (expires_at IS NULL OR expires_at > NOW())
          AND (max_uses IS NULL OR used_count < max_uses)
          AND NOT EXISTS (SELECT 1 FROM promo_code_customers WHERE promo_code_id=pc.id)
          ORDER BY created_at DESC LIMIT 5", [$bt['id']])->fetchAll());
}
// Des qu'un client tape son telephone au checkout, on lui revele lui-meme
// tout code qui lui a ete personnellement reserve - le marchand n'a donc
// pas besoin de le contacter un par un pour le prevenir. Rate-limite : sans
// ca, un tiers pourrait essayer des numeros en serie pour decouvrir qui a
// un code (peu grave en soi, mais autant limiter le bruit).
function shop_promo_for_phone() {
    rate_limit_check('shop_promo_for_phone', 30, 300);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $phone = trim($b['phone'] ?? '');
    if ($phone === '') ok([]);
    ok(q("SELECT pc.code, pc.type, pc.value FROM promo_codes pc
          JOIN promo_code_customers pcc ON pcc.promo_code_id = pc.id
          WHERE pc.boutique_id=? AND RIGHT(regexp_replace(pcc.phone,'\D','','g'),8)=? AND pc.active=1
          AND (pc.expires_at IS NULL OR pc.expires_at > NOW())
          AND (pc.max_uses IS NULL OR pc.used_count < pc.max_uses)
          ORDER BY pc.created_at DESC", [$bt['id'], phone_key($phone)])->fetchAll());
}

// Commande a la livraison : cree/retrouve le client par telephone, cree la
// commande + ses lignes, decremente le stock. Tout dans une transaction
// pour ne jamais laisser une commande a moitie ecrite en cas d'erreur.
function shop_checkout() {
    rate_limit_check('shop_checkout', 20, 300);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $customer = $b['customer'] ?? [];
    $name = trim($customer['name'] ?? '');
    $phone = trim($customer['phone'] ?? '');
    $address = trim($customer['address'] ?? '');
    $items = $b['items'] ?? [];
    if ($name === '' || $phone === '') fail('Nom et telephone requis');
    if (!is_array($items) || count($items) === 0) fail('Le panier est vide');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $customerEmail = trim($customer['email'] ?? '');
        $customerRow = q("SELECT id FROM customers WHERE boutique_id=? AND phone=?", [$bt['id'], $phone])->fetch();
        if ($customerRow) {
            $customerId = $customerRow['id'];
            q("UPDATE customers SET name=?, address=?, email=COALESCE(NULLIF(?,''), email) WHERE id=?", [$name, $address, $customerEmail, $customerId]);
        } else {
            $customerId = uid();
            q("INSERT INTO customers (id,boutique_id,name,phone,email,address) VALUES (?,?,?,?,?,?)",
              [$customerId, $bt['id'], $name, $phone, $customerEmail, $address]);
        }

        $subtotal = 0;
        $lineData = [];
        foreach ($items as $it) {
            $productId = $it['product_id'] ?? '';
            $qty = max(1, (int)($it['qty'] ?? 1));
            $product = q("SELECT * FROM products WHERE id=? AND boutique_id=? AND status='active'", [$productId, $bt['id']])->fetch();
            if (!$product) throw new Exception('Produit indisponible');
            $variant = null;
            if (!empty($it['variant_id'])) {
                $variant = q("SELECT * FROM product_variants WHERE id=? AND product_id=?", [$it['variant_id'], $productId])->fetch();
            }
            $unitPrice = $variant && $variant['price'] !== null ? (float)$variant['price'] : (float)$product['price'];
            // Meme reduction "promotion produit" que celle affichee sur la
            // vitrine (voir apply_active_promotion()) - recalculee ici a
            // partir de la base, jamais a partir d'un prix envoye par le
            // client, pour ne jamais faire confiance a un montant client.
            $unitPrice = effective_unit_price($productId, $unitPrice);
            // Une commande n'est jamais bloquee par manque de stock - c'est
            // au marchand de s'organiser une fois la commande recue, pas a
            // l'acheteur de le decouvrir au moment de payer. Le stock est
            // simplement decompte (et peut devenir negatif, ce qui signale
            // au marchand qu'il a vendu plus que ce qu'il avait) tant que
            // le produit suit sa quantite (track_inventory).
            $subtotal += $unitPrice * $qty;
            $lineData[] = [
                'product' => $product, 'variant' => $variant, 'qty' => $qty, 'unit_price' => $unitPrice,
            ];
        }
        // "Paiement a la livraison" n'a de sens que pour un panier contenant
        // au moins un produit physique - un panier 100% numerique n'a rien a
        // livrer, donc ne doit pas etre bloque si le marchand a desactive le
        // COD (un marchand qui ne vend que du numerique n'a aucune raison de
        // l'activer).
        $hasPhysical = false;
        foreach ($lineData as $l) { if (!empty($l['product']['is_physical'])) { $hasPhysical = true; break; } }
        if ($hasPhysical && !$bt['cod_enabled']) {
            throw new Exception('Le paiement a la livraison n\'est pas active pour cette boutique');
        }
        // L'email n'est obligatoire que si le panier contient au moins un
        // produit numerique (is_digital) - c'est le seul moyen de lui faire
        // parvenir sa livraison numerique plus tard (voir
        // maybe_send_digital_delivery()). Independant de is_physical : un
        // produit peut etre physique ET numerique a la fois.
        foreach ($lineData as $l) {
            if (!empty($l['product']['is_digital']) && $customerEmail === '') {
                throw new Exception('Un email est requis pour recevoir un produit numerique');
            }
        }
        // Le frais de livraison/expedition vient de la boutique (reglage
        // marchand), pas du client - jamais du corps de la requete publique,
        // pour eviter qu'un acheteur ne le mette a 0 lui-meme. Chaque produit
        // peut definir son propre frais (sinon celui de la boutique
        // s'applique) ; une commande n'est livree/expediee qu'une fois, donc
        // on retient le plus eleve des frais concernes plutot que de les
        // additionner - et les produits non physiques (service/numerique)
        // n'en ajoutent aucun. Le client choisit un SEUL des trois modes
        // (jamais deux a la fois) - deliveryMethod par defaut 'delivery' si
        // absent/invalide, pour ne jamais rejeter une vieille requete qui
        // n'envoie pas encore ce champ. En mode "Expedition", un second
        // palier s'applique si le pays de destination saisi par le client
        // differe du pays de la boutique (expedition internationale) -
        // customerCountry vide (client n'a pas encore choisi, ou boutique
        // sans pays configure) retombe sur le tarif "meme pays" par defaut.
        $deliveryMethod = in_array($b['delivery_method'] ?? '', ['delivery', 'shipping'], true) ? $b['delivery_method'] : 'delivery';
        $customerCountry = $deliveryMethod === 'shipping' ? trim($b['customer_country'] ?? '') : '';
        $isOtherCountry = $deliveryMethod === 'shipping' && $customerCountry !== '' && $bt['country'] && $customerCountry !== $bt['country'];
        if ($deliveryMethod === 'delivery') { $feeField = 'delivery_fee'; $defaultFeeField = 'default_delivery_fee'; }
        elseif ($isOtherCountry) { $feeField = 'shipping_fee_other_country'; $defaultFeeField = 'default_shipping_fee_other_country'; }
        else { $feeField = 'shipping_fee'; $defaultFeeField = 'default_shipping_fee'; }
        $deliveryFee = 0;
        foreach ($lineData as $l) {
            if (!$l['product']['is_physical']) continue;
            $productFee = $l['product'][$feeField];
            $fee = ($productFee !== null) ? (float)$productFee : (float)$bt[$defaultFeeField];
            $deliveryFee = max($deliveryFee, $fee);
        }
        // Code promo revalide ici (dans la transaction, sur le vrai
        // sous-total serveur) plutot que de faire confiance a un montant de
        // reduction envoye par le client - shop_validate_promo() ne sert
        // qu'a l'affichage anticipe au panier.
        $discountAmount = 0;
        $promoCodeUsed = null;
        $promoInput = trim($b['promo_code'] ?? '');
        if ($promoInput !== '') {
            $promo = find_active_promo($bt['id'], $promoInput, $phone);
            if (!$promo) throw new Exception('Code promo invalide ou expire');
            $discountAmount = promo_discount_amount($promo, $subtotal);
            $promoCodeUsed = $promo['code'];
            q("UPDATE promo_codes SET used_count = used_count + 1 WHERE id=?", [$promo['id']]);
        }
        $total = $subtotal + $deliveryFee - $discountAmount;

        $orderId = uid();
        $ref = order_ref();
        q("INSERT INTO orders (id,boutique_id,customer_id,ref,status,payment_method,subtotal,delivery_fee_charged,delivery_method,customer_country,total,
           customer_name,customer_phone,customer_address,utm_source,utm_campaign,promo_code,discount_amount)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$orderId, $bt['id'], $customerId, $ref, 'pending', 'cod', $subtotal, $deliveryFee, $deliveryMethod, ($customerCountry !== '' ? $customerCountry : null), $total,
           $name, $phone, $address, trim($b['utm_source'] ?? ''), trim($b['utm_campaign'] ?? ''), $promoCodeUsed, $discountAmount]);

        foreach ($lineData as $l) {
            q("INSERT INTO order_items (id,order_id,product_id,product_name,variant_id,unit_price,unit_cost,qty)
               VALUES (?,?,?,?,?,?,?,?)",
              [uid(), $orderId, $l['product']['id'],
               $l['product']['name'].($l['variant'] ? ' - '.$l['variant']['name'] : ''),
               $l['variant']['id'] ?? null, $l['unit_price'], $l['product']['cost_price'] ?? 0, $l['qty']]);
            if (!$l['product']['track_inventory']) {
                // Stock illimite pour ce produit : rien a decompter.
            } elseif ($l['variant']) {
                q("UPDATE product_variants SET stock_qty = stock_qty - ? WHERE id=?", [$l['qty'], $l['variant']['id']]);
            } else {
                q("UPDATE products SET stock_qty = stock_qty - ? WHERE id=?", [$l['qty'], $l['product']['id']]);
            }
        }
        q("INSERT INTO delivery_assignments (id,order_id,boutique_id,status) VALUES (?,?,?,?)",
          [uid(), $orderId, $bt['id'], 'to_assign']);

        if (!empty($b['session_id'])) {
            q("UPDATE abandoned_carts SET converted=1 WHERE boutique_id=? AND session_id=? AND converted=0",
              [$bt['id'], $b['session_id']]);
        }

        $pdo->commit();
        log_activity($bt['id'], 'Nouvelle commande '.$ref.' ('.$name.')');
        notify_new_order($bt, $ref, $name, $total);
        if ($customerEmail !== '') {
            notify_customer_order_confirmation($bt, $ref, $customerEmail, $name, $lineData, $subtotal, $deliveryFee, $discountAmount, $total, $address, $deliveryMethod, $customerCountry);
        }
        ok(['ref'=>$ref, 'order_id'=>$orderId, 'total'=>$total], 'Commande enregistree', 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        fail($e->getMessage(), 400);
    }
}

function notify_new_order($bt, $ref, $customerName, $total) {
    $settings = q("SELECT notify_order_email, notify_email FROM boutiques WHERE id=?", [$bt['id']])->fetch();
    if (!$settings) return;
    $summary = "Client: $customerName\nTotal: $total ".($bt['currency'] ?: 'XOF')."\nReference: $ref\n\nOuvrez votre tableau de bord MYBOUTIK pour la traiter.";
    if ($settings['notify_order_email']) {
        $to = $settings['notify_email'] ?: null;
        if (!$to) {
            $owner = q("SELECT u.email FROM users u JOIN boutiques b ON b.owner_user_id=u.id WHERE b.id=?", [$bt['id']])->fetch();
            $to = $owner['email'] ?? null;
        }
        if ($to) send_email($to, 'Nouvelle commande '.$ref.' - '.$bt['name'], $summary);
    }
}

// Recu envoye au CLIENT (par opposition a notify_new_order() qui previent le
// MARCHAND) - uniquement si une adresse email a ete renseignee au checkout
// (le champ est optionnel, voir store/index.html). N'est jamais bloquant :
// appele apres le commit de la transaction, une erreur d'envoi ne doit
// jamais faire echouer une commande deja enregistree.
function notify_customer_order_confirmation($bt, $ref, $email, $customerName, $lineData, $subtotal, $deliveryFee, $discountAmount, $total, $address, $deliveryMethod = 'delivery', $customerCountry = '') {
    $currency = $bt['currency'] ?: 'XOF';
    $lines = array_map(function($l) use ($currency) {
        $label = $l['product']['name'].($l['variant'] ? ' - '.$l['variant']['name'] : '');
        return '- '.$l['qty'].' x '.$label.' ('.money_fmt($l['unit_price'], $currency).' '.$currency.')';
    }, $lineData);
    // Le mot "livraison"/"expedition" (adresse, "vous serez contacte pour...")
    // n'a de sens que si la commande contient au moins un produit physique -
    // pour un panier 100% numerique, rien n'est jamais livre/expedie.
    $isShipping = $deliveryMethod === 'shipping';
    $modeWord = $isShipping ? 'expedition' : 'livraison';
    $hasPhysical = false; $hasDigital = false;
    foreach ($lineData as $l) {
        if (!empty($l['product']['is_physical'])) $hasPhysical = true;
        if (!empty($l['product']['is_digital'])) $hasDigital = true;
    }
    $totalLabel = $hasPhysical ? 'Total a payer a la '.$modeWord : 'Total';
    $footer = '';
    if ($hasPhysical) {
        $footer .= ($isShipping ? "Adresse d'expedition : $address\n\n" : "Adresse de livraison : $address\n\n").
            ($isShipping && $customerCountry !== '' ? "Pays de destination : $customerCountry\n\n" : '').
            "Vous serez contacte(e) pour la ".$modeWord.". Paiement a la reception (paiement a la ".$modeWord.").\n";
    }
    if ($hasDigital) {
        $footer .= "Votre produit numerique vous sera envoye par email des que ".$bt['name']." aura confirme votre paiement.\n";
    }
    $body = "Bonjour $customerName,\n\n".
        "Merci pour votre commande chez ".$bt['name']." !\n\n".
        "Reference : $ref\n\n".
        implode("\n", $lines)."\n\n".
        "Sous-total : ".money_fmt($subtotal, $currency)." $currency\n".
        ($deliveryFee > 0 ? "Frais de ".$modeWord." : ".money_fmt($deliveryFee, $currency)." $currency\n" : '').
        ($discountAmount > 0 ? "Remise : -".money_fmt($discountAmount, $currency)." $currency\n" : '').
        "$totalLabel : ".money_fmt($total, $currency)." $currency\n\n".
        $footer.
        "Pour suivre votre commande, retournez sur la boutique et utilisez \"Suivre ma commande\" avec cette reference et votre telephone.";
    send_email($email, 'Confirmation de votre commande '.$ref.' - '.$bt['name'], $body);
}

function shop_track_visit() {
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    q("INSERT INTO visits (boutique_id,session_id,path,referrer) VALUES (?,?,?,?)",
      [$bt['id'], substr(trim($b['session_id'] ?? ''),0,64), substr(trim($b['path'] ?? ''),0,255), substr(trim($b['referrer'] ?? ''),0,255)]);
    ok(null);
}

function shop_track_abandoned() {
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $settings = q("SELECT * FROM abandoned_settings WHERE boutique_id=?", [$bt['id']])->fetch();
    if ($settings && !$settings['capture_enabled']) ok(null);
    $sessionId = trim($b['session_id'] ?? '');
    $phone = trim($b['phone'] ?? '');
    $email = trim($b['email'] ?? '');
    if ($phone === '' && $email === '') fail('Aucune information a capturer');
    $existing = $sessionId ? q("SELECT id FROM abandoned_carts WHERE boutique_id=? AND session_id=? AND converted=0",
        [$bt['id'], $sessionId])->fetch() : null;
    $cartJson = json_encode($b['cart'] ?? [], JSON_UNESCAPED_UNICODE);
    $total = (float)($b['total'] ?? 0);
    if ($existing) {
        q("UPDATE abandoned_carts SET phone=?, email=?, cart_snapshot=?, total=?, captured_at=NOW() WHERE id=?",
          [$phone, $email, $cartJson, $total, $existing['id']]);
    } else {
        q("INSERT INTO abandoned_carts (id,boutique_id,session_id,phone,email,cart_snapshot,total)
           VALUES (?,?,?,?,?,?,?)", [uid(), $bt['id'], $sessionId, $phone, $email, $cartJson, $total]);
    }
    ok(null);
}

function shop_newsletter() {
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $email = strtolower(trim($b['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse email invalide');
    q("INSERT INTO newsletter_subscribers (id,boutique_id,email) VALUES (?,?,?) ON CONFLICT (boutique_id,email) DO NOTHING",
      [uid(), $bt['id'], $email]);
    ok(null, 'Inscription confirmee');
}

function shop_contact_message() {
    rate_limit_check('shop_contact', 10, 300);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $message = trim($b['message'] ?? '');
    if ($message === '') fail('Le message ne peut pas etre vide');
    q("INSERT INTO contact_messages (id,boutique_id,name,email,message) VALUES (?,?,?,?,?)",
      [uid(), $bt['id'], trim($b['name'] ?? ''), trim($b['email'] ?? ''), $message]);
    ok(null, 'Message envoye');
}

// Suivi de commande public : le client doit connaitre a la fois la
// reference ET le telephone utilise a la commande - empeche quiconque
// devinant/enumerant des references de voir les commandes des autres
// clients de la boutique.
function shop_track_order() {
    rate_limit_check('shop_track_order', 20, 300);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $ref = trim($b['ref'] ?? '');
    $phone = trim($b['phone'] ?? '');
    if ($ref === '' || $phone === '') fail('Numero de commande et telephone requis');
    $o = q("SELECT id,ref,status,total,subtotal,delivery_fee_charged,delivery_method,discount_amount,customer_name,created_at,delivered_at,
                   dispute_status,dispute_message,dispute_response,dispute_created_at,dispute_resolved_at
            FROM orders WHERE boutique_id=? AND ref=? AND RIGHT(regexp_replace(customer_phone,'\D','','g'),8)=?",
           [$bt['id'], $ref, phone_key($phone)])->fetch();
    if (!$o) fail('Aucune commande trouvee avec ces informations', 404);
    $o['items'] = q("SELECT product_name,qty,unit_price FROM order_items WHERE order_id=?", [$o['id']])->fetchAll();
    $delivery = q("SELECT da.status AS delivery_status, dp.name AS delivery_person_name, dp.phone AS delivery_person_phone
                   FROM delivery_assignments da LEFT JOIN delivery_persons dp ON dp.id = da.delivery_person_id
                   WHERE da.order_id=?", [$o['id']])->fetch();
    $o['delivery_status'] = $delivery['delivery_status'] ?? null;
    $o['delivery_person_name'] = $delivery['delivery_person_name'] ?? null;
    $o['delivery_person_phone'] = $delivery['delivery_person_phone'] ?? null;
    ok($o);
}

// Reclamation client apres livraison ("le produit ne correspond pas a mes
// attentes") - meme couple ref+telephone que shop_track_order() pour
// s'assurer que seul le client de cette commande peut la signaler. Limitee
// aux commandes deja livrees (avant ça, "livraison" au sens du client n'a
// pas encore eu lieu) et a une reclamation ouverte a la fois par commande.
function shop_submit_dispute() {
    rate_limit_check('shop_submit_dispute', 10, 300);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $ref = trim($b['ref'] ?? '');
    $phone = trim($b['phone'] ?? '');
    $message = trim($b['message'] ?? '');
    if ($ref === '' || $phone === '') fail('Numero de commande et telephone requis');
    if ($message === '') fail('Decrivez le probleme rencontre');
    $o = q("SELECT id, status, dispute_status, customer_name FROM orders
            WHERE boutique_id=? AND ref=? AND RIGHT(regexp_replace(customer_phone,'\D','','g'),8)=?",
           [$bt['id'], $ref, phone_key($phone)])->fetch();
    if (!$o) fail('Aucune commande trouvee avec ces informations', 404);
    if ($o['status'] !== 'delivered') fail('La reclamation n\'est disponible que pour une commande deja livree', 400);
    if ($o['dispute_status'] === 'open') fail('Une reclamation est deja en cours de traitement pour cette commande');
    q("UPDATE orders SET dispute_status='open', dispute_message=?, dispute_created_at=NOW(), dispute_response=NULL, dispute_resolved_at=NULL WHERE id=?",
      [$message, $o['id']]);
    notify_new_dispute($bt, $ref, $o['customer_name'], $message);
    notify_admin_new_dispute($bt, $ref, $message);
    ok(null, 'Votre reclamation a ete envoyee au marchand.');
}
function notify_new_dispute($bt, $ref, $customerName, $message) {
    $settings = q("SELECT notify_order_email, notify_email FROM boutiques WHERE id=?", [$bt['id']])->fetch();
    if (!$settings || !$settings['notify_order_email']) return;
    $to = $settings['notify_email'] ?: null;
    if (!$to) {
        $owner = q("SELECT u.email FROM users u JOIN boutiques b ON b.owner_user_id=u.id WHERE b.id=?", [$bt['id']])->fetch();
        $to = $owner['email'] ?? null;
    }
    if (!$to) return;
    $summary = "Client: $customerName\nCommande: $ref\n\nMessage du client:\n$message\n\nOuvrez votre tableau de bord MYBOUTIK (Commandes) pour repondre.";
    send_email($to, 'Reclamation sur la commande '.$ref.' - '.$bt['name'], $summary);
}
// Alerte qualite envoyee a l'operateur (ADMIN_NOTIFY_EMAIL, optionnelle) a
// chaque nouvelle reclamation, avec un objet plus alarmant si la boutique
// en cumule deja plusieurs en attente - evite d'avoir a ouvrir le panneau
// admin regulierement juste pour verifier qu'aucune boutique ne derape.
function notify_admin_new_dispute($bt, $ref, $message) {
    if (!ADMIN_NOTIFY_EMAIL) return;
    $openCount = (int)q("SELECT COUNT(*) c FROM orders WHERE boutique_id=? AND dispute_status='open'", [$bt['id']])->fetch()['c'];
    $subject = $openCount >= 3
        ? 'ALERTE qualite : '.$bt['name'].' cumule '.$openCount.' reclamations en attente'
        : 'Nouvelle reclamation - '.$bt['name'];
    $body = "Boutique: {$bt['name']} ({$bt['slug']})\nCommande: $ref\n\nMessage du client:\n$message\n\n".
            "Reclamations en attente pour cette boutique : $openCount\n\nPanneau admin : ".FRONTEND_BASE_URL."/admin.html";
    send_email(ADMIN_NOTIFY_EMAIL, $subject, $body);
}

// Liste des commandes d'un numero (sans reference) - permet au client qui a
// oublie/perdu sa reference de la retrouver lui-meme plutot que de devoir
// vous contacter. Le telephone seul suffit ici (comme pour
// shop_promo_for_phone()) : c'est sa propre historique, pas celle de
// quelqu'un d'autre - il ne peut deviner que son propre numero.
function shop_orders_for_phone() {
    rate_limit_check('shop_orders_for_phone', 20, 300);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $phone = trim($b['phone'] ?? '');
    if ($phone === '') ok([]);
    ok(q("SELECT ref, status, total, created_at FROM orders
          WHERE boutique_id=? AND RIGHT(regexp_replace(customer_phone,'\D','','g'),8)=?
          ORDER BY created_at DESC LIMIT 20", [$bt['id'], phone_key($phone)])->fetchAll());
}

function shop_reviews() {
    $bt = public_boutique_by_slug($_GET['slug'] ?? '');
    $productId = $_GET['product_id'] ?? '';
    $rows = q("SELECT customer_name,rating,comment,created_at FROM product_reviews
               WHERE boutique_id=? AND product_id=? AND status='approved' ORDER BY created_at DESC LIMIT 100",
              [$bt['id'], $productId])->fetchAll();
    ok($rows);
}

function shop_review_add() {
    rate_limit_check('shop_review_add', 10, 600);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $product = product_owned($b['product_id'] ?? '', $bt['id']);
    $name = trim($b['customer_name'] ?? '');
    $rating = (int)($b['rating'] ?? 0);
    if ($name === '') fail('Votre nom est requis');
    if ($rating < 1 || $rating > 5) fail('Note invalide (1 a 5)');
    // Publie immediatement (status='approved') : le marchand n'a plus a valider
    // avant affichage, mais garde la main pour supprimer un avis apres coup
    // depuis Marketing > Avis (voir marketing_review_delete()).
    q("INSERT INTO product_reviews (id,boutique_id,product_id,customer_name,rating,comment,status) VALUES (?,?,?,?,?,?,'approved')",
      [uid(), $bt['id'], $product['id'], $name, $rating, trim($b['comment'] ?? '')]);
    ok(null, 'Merci pour votre avis !');
}

// ============================================================
// COMMANDES — gestion cote marchand (liste, statut, creation manuelle) +
// commandes abandonnees
// ============================================================
function route_orders($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'orders');
    switch ($action) {
        case 'list':               orders_list($pl); break;
        case 'get':                orders_get($pl); break;
        case 'create':              orders_create_manual($pl); break;
        case 'update_status':       orders_update_status($pl); break;
        case 'respond_dispute':     orders_respond_dispute($pl); break;
        case 'resend_digital':      orders_resend_digital($pl); break;
        case 'abandoned_list':      abandoned_list($pl); break;
        case 'abandoned_mark':      abandoned_mark($pl); break;
        case 'abandoned_settings_get':  abandoned_settings_get($pl); break;
        case 'abandoned_settings_save': abandoned_settings_save($pl); break;
        default: fail('Action inconnue', 404);
    }
}

function order_owned($id, $boutiqueId) {
    $o = q("SELECT * FROM orders WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$o) fail('Commande introuvable', 404);
    return $o;
}

function orders_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $status = $_GET['status'] ?? '';
    $qStr = trim($_GET['q'] ?? '');
    // has_physical/has_digital : permettent d'afficher un badge par commande
    // (une commande peut melanger les deux si le panier avait un produit de
    // chaque sorte) sans avoir a rappeler orders_get() pour chaque ligne.
    $sql = "SELECT o.*,
              CAST(EXISTS(SELECT 1 FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=o.id AND p.is_physical=1) AS INT) AS has_physical,
              CAST(EXISTS(SELECT 1 FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=o.id AND p.is_digital=1) AS INT) AS has_digital
            FROM orders o WHERE o.boutique_id=?";
    $params = [$bt['id']];
    if ($status !== '' && $status !== 'all') { $sql .= " AND o.status=?"; $params[] = $status; }
    if ($qStr !== '') {
        $sql .= " AND (o.ref ILIKE ? OR o.customer_name ILIKE ? OR o.customer_phone ILIKE ?)";
        $like = '%'.$qStr.'%'; array_push($params, $like, $like, $like);
    }
    $sql .= " ORDER BY o.created_at DESC LIMIT 500";
    $rows = q($sql, $params)->fetchAll();
    ok($rows);
}

function orders_get($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $o = order_owned($_GET['id'] ?? '', $bt['id']);
    $o['items'] = q("SELECT * FROM order_items WHERE order_id=?", [$o['id']])->fetchAll();
    $o['delivery'] = q("SELECT da.*, dp.name AS delivery_person_name, dp.phone AS delivery_person_phone
                         FROM delivery_assignments da LEFT JOIN delivery_persons dp ON dp.id = da.delivery_person_id
                         WHERE da.order_id=?", [$o['id']])->fetch();
    ok($o);
}

function orders_create_manual($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['customer_name'] ?? '');
    $phone = trim($b['customer_phone'] ?? '');
    $items = $b['items'] ?? [];
    if ($name === '' || $phone === '') fail('Nom et telephone du client requis');
    if (!is_array($items) || count($items) === 0) fail('Ajoutez au moins un produit');

    $customerRow = q("SELECT id FROM customers WHERE boutique_id=? AND phone=?", [$bt['id'], $phone])->fetch();
    if ($customerRow) {
        $customerId = $customerRow['id'];
        q("UPDATE customers SET name=?, address=? WHERE id=?", [$name, trim($b['customer_address'] ?? ''), $customerId]);
    } else {
        $customerId = uid();
        q("INSERT INTO customers (id,boutique_id,name,phone,address) VALUES (?,?,?,?,?)",
          [$customerId, $bt['id'], $name, $phone, trim($b['customer_address'] ?? '')]);
    }

    $subtotal = 0; $lineData = [];
    foreach ($items as $it) {
        $product = product_owned($it['product_id'] ?? '', $bt['id']);
        $qty = max(1, (int)($it['qty'] ?? 1));
        $unitPrice = isset($it['unit_price']) ? (float)$it['unit_price'] : (float)$product['price'];
        $subtotal += $unitPrice * $qty;
        $lineData[] = ['product'=>$product, 'qty'=>$qty, 'unit_price'=>$unitPrice];
    }
    $deliveryFee = max(0, (float)($b['delivery_fee'] ?? 0));
    $total = $subtotal + $deliveryFee;
    $orderId = uid(); $ref = order_ref();
    q("INSERT INTO orders (id,boutique_id,customer_id,ref,status,payment_method,subtotal,delivery_fee_charged,total,
       customer_name,customer_phone,customer_address) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
      [$orderId, $bt['id'], $customerId, $ref, 'pending', 'cod', $subtotal, $deliveryFee, $total,
       $name, $phone, trim($b['customer_address'] ?? '')]);
    foreach ($lineData as $l) {
        q("INSERT INTO order_items (id,order_id,product_id,product_name,unit_price,unit_cost,qty) VALUES (?,?,?,?,?,?,?)",
          [uid(), $orderId, $l['product']['id'], $l['product']['name'], $l['unit_price'], $l['product']['cost_price'] ?? 0, $l['qty']]);
        q("UPDATE products SET stock_qty = stock_qty - ? WHERE id=?", [$l['qty'], $l['product']['id']]);
    }
    q("INSERT INTO delivery_assignments (id,order_id,boutique_id,status) VALUES (?,?,?,?)", [uid(), $orderId, $bt['id'], 'to_assign']);
    log_activity($bt['id'], 'Commande manuelle creee '.$ref, $pl['sub']);
    ok(q("SELECT * FROM orders WHERE id=?", [$orderId])->fetch(), 'Commande creee', 201);
}

function orders_update_status($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $o = order_owned($b['id'] ?? '', $bt['id']);
    $status = $b['status'] ?? '';
    $allowed = ['pending','processing','shipped','delivered','refused','cancelled'];
    if (!in_array($status, $allowed, true)) fail('Statut invalide');
    if ($status === 'delivered') {
        q("UPDATE orders SET status=?, delivered_at=NOW() WHERE id=?", [$status, $o['id']]);
        maybe_send_digital_delivery($bt, $o);
    } else {
        q("UPDATE orders SET status=? WHERE id=?", [$status, $o['id']]);
    }
    log_activity($bt['id'], 'Commande '.$o['ref'].' -> '.$status, $pl['sub']);
    ok(q("SELECT * FROM orders WHERE id=?", [$o['id']])->fetch(), 'Statut mis a jour');
}

// Envoie automatiquement, par email, le contenu numerique (lien, code,
// instructions...) des produits non physiques de la commande, des qu'elle
// passe au statut "livree" - c'est ce statut qui represente pour un produit
// numerique le moment ou le client recoit vraiment ce qu'il a paye. Ne fait
// rien si : deja envoye pour cette commande (digital_delivery_sent_at),
// aucun email client connu, ou aucun article non physique n'a de contenu
// renseigne par le marchand.
// $claimNewCodes=true (premier envoi) : pioche de nouveaux codes dans le
// pool. $claimNewCodes=false (renvoi manuel, voir orders_resend_digital()) :
// reutilise les codes DEJA attribues a cette commande plutot que d'en piocher
// de nouveaux - un renvoi doit redonner exactement ce que le client a deja
// recu, jamais un code different ni consommer le pool une deuxieme fois.
function digital_delivery_parts($bt, $o, $claimNewCodes) {
    $items = q("SELECT oi.product_id, oi.product_name, oi.qty, p.is_digital, p.digital_delivery_content
                FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id=?", [$o['id']])->fetchAll();
    $parts = [];
    foreach ($items as $it) {
        if (empty($it['is_digital'])) continue;
        // Les deux sources sont independantes et se cumulent : le contenu
        // partage (meme lien/instructions pour tout le monde) ET un code
        // unique pioche dans le pool, si le produit en a un - utile pour un
        // logiciel ou le lien de telechargement est le meme pour tous mais
        // la cle de licence doit etre differente a chaque vente.
        $pieces = [];
        $content = trim((string)($it['digital_delivery_content'] ?? ''));
        if ($content !== '') $pieces[] = $content;
        if ($claimNewCodes) {
            $poolSize = (int)q("SELECT COUNT(*) c FROM product_digital_codes WHERE product_id=?", [$it['product_id']])->fetch()['c'];
            if ($poolSize > 0) {
                // Pioche atomique d'un code disponible par unite commandee :
                // le SELECT ... FOR UPDATE SKIP LOCKED evite que deux
                // commandes du meme produit livrees en meme temps ne
                // recuperent le meme code.
                $codes = [];
                for ($i = 0; $i < (int)$it['qty']; $i++) {
                    $code = q("UPDATE product_digital_codes SET status='used', used_by_order_id=?, used_at=NOW()
                               WHERE id = (SELECT id FROM product_digital_codes WHERE product_id=? AND status='available'
                                           ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED)
                               RETURNING code", [$o['id'], $it['product_id']])->fetchColumn();
                    if ($code === false) break;
                    $codes[] = $code;
                }
                if ($codes) $pieces[] = (count($codes) > 1 ? 'Vos codes' : 'Votre code').' : '.implode(', ', $codes);
                if (count($codes) < (int)$it['qty']) {
                    log_activity($bt['id'], 'Stock de codes numeriques epuise pour '.$it['product_name'].' (commande '.$o['ref'].')');
                }
            }
        } else {
            $codes = q("SELECT code FROM product_digital_codes WHERE product_id=? AND used_by_order_id=?",
                       [$it['product_id'], $o['id']])->fetchAll(PDO::FETCH_COLUMN);
            if ($codes) $pieces[] = (count($codes) > 1 ? 'Vos codes' : 'Votre code').' : '.implode(', ', $codes);
        }
        if ($pieces) $parts[] = $it['product_name'].":\n".implode("\n", $pieces);
    }
    return $parts;
}

function maybe_send_digital_delivery($bt, $o) {
    if (!empty($o['digital_delivery_sent_at'])) return;
    if (empty($o['customer_id'])) return;
    $email = trim((string)(q("SELECT email FROM customers WHERE id=?", [$o['customer_id']])->fetchColumn() ?: ''));
    if ($email === '') return;
    $parts = digital_delivery_parts($bt, $o, true);
    if (!$parts) return;
    $body = "Bonjour,\n\nMerci pour votre commande ".$o['ref']." chez ".$bt['name'].
        " ! Voici votre/vos produit(s) numerique(s) :\n\n".implode("\n\n", $parts).
        "\n\nBonne utilisation !";
    send_email($email, 'Votre produit numerique - commande '.$o['ref'].' - '.$bt['name'], $body);
    q("UPDATE orders SET digital_delivery_sent_at=NOW() WHERE id=?", [$o['id']]);
}

// Reponse du marchand a une reclamation client (shop_submit_dispute()) -
// marque la reclamation comme resolue avec le message de reponse ; ne
// renvoie rien au client par email (aucune adresse email fiable stockee sur
// la commande), il la consultera en revisitant "Suivre ma commande".
function orders_respond_dispute($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $o = order_owned($b['id'] ?? '', $bt['id']);
    if ($o['dispute_status'] !== 'open') fail('Aucune reclamation ouverte pour cette commande', 400);
    $response = trim($b['response'] ?? '');
    if ($response === '') fail('Ecrivez une reponse');
    q("UPDATE orders SET dispute_status='resolved', dispute_response=?, dispute_resolved_at=NOW() WHERE id=?", [$response, $o['id']]);
    log_activity($bt['id'], 'Reclamation resolue pour la commande '.$o['ref'], $pl['sub']);
    ok(q("SELECT * FROM orders WHERE id=?", [$o['id']])->fetch(), 'Reponse envoyee');
}

// Renvoi manuel a la demande du marchand (bouton "Renvoyer" sur une commande
// numerique deja livree) - typiquement quand le client dit ne rien avoir
// recu (spam, adresse mal tapee corrigee entre temps...). Contrairement au
// premier envoi, jamais bloque par digital_delivery_sent_at : c'est prevu
// pour etre redeclenchable autant de fois que necessaire.
function orders_resend_digital($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $o = order_owned($b['id'] ?? '', $bt['id']);
    if (empty($o['customer_id'])) fail('Client introuvable');
    $email = trim((string)(q("SELECT email FROM customers WHERE id=?", [$o['customer_id']])->fetchColumn() ?: ''));
    if ($email === '') fail('Ce client n\'a pas d\'email enregistre');
    $parts = digital_delivery_parts($bt, $o, false);
    if (!$parts) fail('Aucun contenu numerique a renvoyer pour cette commande');
    $body = "Bonjour,\n\nVoici a nouveau votre/vos produit(s) numerique(s) pour la commande ".$o['ref']." chez ".$bt['name'].
        " :\n\n".implode("\n\n", $parts)."\n\nBonne utilisation !";
    send_email($email, 'Votre produit numerique - commande '.$o['ref'].' - '.$bt['name'], $body);
    q("UPDATE orders SET digital_delivery_sent_at=NOW() WHERE id=?", [$o['id']]);
    log_activity($bt['id'], 'Livraison numerique renvoyee pour la commande '.$o['ref'], $pl['sub']);
    ok(null, 'Email renvoye a '.$email);
}

function abandoned_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $filter = $_GET['filter'] ?? 'abandoned';
    $sql = "SELECT * FROM abandoned_carts WHERE boutique_id=?";
    $params = [$bt['id']];
    if ($filter === 'abandoned') { $sql .= " AND converted=0"; }
    elseif ($filter === 'converted') { $sql .= " AND converted=1"; }
    $sql .= " ORDER BY captured_at DESC LIMIT 500";
    ok(q($sql, $params)->fetchAll());
}
function abandoned_mark($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = q("SELECT id FROM abandoned_carts WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']])->fetch();
    if (!$row) fail('Introuvable', 404);
    q("UPDATE abandoned_carts SET converted=? WHERE id=?", [(int)!!($b['converted'] ?? true), $row['id']]);
    ok(null, 'Mis a jour');
}
function abandoned_settings_get($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $row = q("SELECT * FROM abandoned_settings WHERE boutique_id=?", [$bt['id']])->fetch();
    ok($row ?: ['boutique_id'=>$bt['id'],'capture_enabled'=>1,'timeout_minutes'=>15,'email_alert'=>0,'customer_reminder_enabled'=>0]);
}
function abandoned_settings_save($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    q("INSERT INTO abandoned_settings (boutique_id,capture_enabled,timeout_minutes,email_alert,customer_reminder_enabled) VALUES (?,?,?,?,?)
       ON CONFLICT (boutique_id) DO UPDATE SET capture_enabled=EXCLUDED.capture_enabled,
       timeout_minutes=EXCLUDED.timeout_minutes, email_alert=EXCLUDED.email_alert,
       customer_reminder_enabled=EXCLUDED.customer_reminder_enabled",
      [$bt['id'], (int)!!($b['capture_enabled'] ?? 1), (int)($b['timeout_minutes'] ?? 15), (int)!!($b['email_alert'] ?? 0),
       (int)!!($b['customer_reminder_enabled'] ?? 0)]);
    ok(null, 'Reglages enregistres');
}

// ============================================================
// LIVRAISONS — livreurs et suivi de la livraison de chaque commande
// ============================================================
function route_deliveries($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'deliveries');
    switch ($action) {
        case 'list':           deliveries_list($pl); break;
        case 'persons':        delivery_persons_list($pl); break;
        case 'person_create':  delivery_person_create($pl); break;
        case 'person_update':  delivery_person_update($pl); break;
        case 'person_delete':  delivery_person_delete($pl); break;
        case 'assign':         delivery_assign($pl); break;
        case 'update_status':  delivery_update_status($pl); break;
        default: fail('Action inconnue', 404);
    }
}

function deliveries_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $status = $_GET['status'] ?? '';
    $sql = "SELECT da.*, o.ref, o.customer_name, o.customer_phone, o.customer_address, o.total,
                   dp.name AS delivery_person_name, dp.phone AS delivery_person_phone
            FROM delivery_assignments da
            JOIN orders o ON o.id = da.order_id
            LEFT JOIN delivery_persons dp ON dp.id = da.delivery_person_id
            WHERE da.boutique_id=?";
    $params = [$bt['id']];
    if ($status !== '' && $status !== 'all') { $sql .= " AND da.status=?"; $params[] = $status; }
    // Un membre 'livreur' ne voit que les livraisons qui lui sont assignees
    // (via son lien delivery_person_id) - pas encore lie : ne voit rien
    // plutot que tout, en attendant que l'admin fasse le lien.
    if (($bt['_member_role'] ?? '') === 'livreur') {
        $dpId = q("SELECT delivery_person_id FROM boutique_members WHERE boutique_id=? AND user_id=? AND status='active'", [$bt['id'], $pl['sub']])->fetchColumn();
        if ($dpId) { $sql .= " AND da.delivery_person_id=?"; $params[] = $dpId; }
        else { $sql .= " AND 1=0"; }
    }
    $sql .= " ORDER BY da.created_at DESC LIMIT 500";
    ok(q($sql, $params)->fetchAll());
}

function delivery_persons_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM delivery_persons WHERE boutique_id=? ORDER BY active DESC, name", [$bt['id']])->fetchAll());
}
function delivery_person_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    deny_roles($bt, ['livreur']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du livreur est requis');
    $id = uid();
    q("INSERT INTO delivery_persons (id,boutique_id,name,phone,email,vehicle_type,plate_number,photo_url,notes)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [$id, $bt['id'], $name, trim($b['phone'] ?? ''), trim($b['email'] ?? ''), trim($b['vehicle_type'] ?? ''),
       trim($b['plate_number'] ?? ''), trim($b['photo_url'] ?? ''), trim($b['notes'] ?? '')]);
    ok(q("SELECT * FROM delivery_persons WHERE id=?", [$id])->fetch(), 'Livreur ajoute', 201);
}
function delivery_person_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM delivery_persons WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Livreur introuvable', 404);
    return $row;
}
function delivery_person_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    deny_roles($bt, ['livreur']);
    $row = delivery_person_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE delivery_persons SET name=?, phone=?, active=?, email=?, vehicle_type=?, plate_number=?, photo_url=?, notes=? WHERE id=?",
      [trim($b['name'] ?? $row['name']), trim($b['phone'] ?? $row['phone']),
       isset($b['active']) ? (int)!!$b['active'] : $row['active'],
       trim($b['email'] ?? $row['email']), trim($b['vehicle_type'] ?? $row['vehicle_type']),
       trim($b['plate_number'] ?? $row['plate_number']), trim($b['photo_url'] ?? $row['photo_url']),
       trim($b['notes'] ?? $row['notes']), $row['id']]);
    ok(q("SELECT * FROM delivery_persons WHERE id=?", [$row['id']])->fetch(), 'Livreur mis a jour');
}
function delivery_person_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    deny_roles($bt, ['livreur']);
    $row = delivery_person_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE delivery_persons SET active=0 WHERE id=?", [$row['id']]);
    ok(null, 'Livreur desactive');
}

function delivery_assign($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    deny_roles($bt, ['livreur']);
    $o = order_owned($b['order_id'] ?? '', $bt['id']);
    delivery_person_owned($b['delivery_person_id'] ?? '', $bt['id']);
    q("UPDATE delivery_assignments SET delivery_person_id=?, status='assigned', assigned_at=NOW() WHERE order_id=?",
      [$b['delivery_person_id'], $o['id']]);
    q("UPDATE orders SET status='processing' WHERE id=? AND status='pending'", [$o['id']]);
    log_activity($bt['id'], 'Commande '.$o['ref'].' assignee a un livreur', $pl['sub']);
    ok(q("SELECT * FROM delivery_assignments WHERE order_id=?", [$o['id']])->fetch(), 'Commande assignee');
}

function delivery_update_status($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $o = order_owned($b['order_id'] ?? '', $bt['id']);
    // Un livreur ne peut faire evoluer que ses propres livraisons assignees,
    // pas n'importe quelle commande de la boutique.
    if (($bt['_member_role'] ?? '') === 'livreur') {
        $dpId = q("SELECT delivery_person_id FROM boutique_members WHERE boutique_id=? AND user_id=? AND status='active'", [$bt['id'], $pl['sub']])->fetchColumn();
        $assignment = q("SELECT delivery_person_id FROM delivery_assignments WHERE order_id=?", [$o['id']])->fetch();
        if (!$dpId || !$assignment || $assignment['delivery_person_id'] !== $dpId) {
            fail('Cette livraison ne vous est pas assignee', 403);
        }
    }
    $status = $b['status'] ?? '';
    $allowed = ['to_assign','assigned','in_delivery','delivered','refused'];
    if (!in_array($status, $allowed, true)) fail('Statut invalide');
    if ($status === 'delivered') {
        q("UPDATE delivery_assignments SET status=?, delivered_at=NOW() WHERE order_id=?", [$status, $o['id']]);
        q("UPDATE orders SET status='delivered', delivered_at=NOW() WHERE id=?", [$o['id']]);
    } else {
        q("UPDATE delivery_assignments SET status=? WHERE order_id=?", [$status, $o['id']]);
        if ($status === 'refused') q("UPDATE orders SET status='refused' WHERE id=?", [$o['id']]);
        if ($status === 'in_delivery') q("UPDATE orders SET status='shipped' WHERE id=?", [$o['id']]);
    }
    log_activity($bt['id'], 'Livraison '.$o['ref'].' -> '.$status, $pl['sub']);
    ok(q("SELECT * FROM delivery_assignments WHERE order_id=?", [$o['id']])->fetch(), 'Statut mis a jour');
}

// ============================================================
// CLIENTS (derives des commandes) + CARNET D'ADRESSES (contacts internes)
// ============================================================
function route_customers($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'customers');
    switch ($action) {
        case 'list':     customers_list($pl); break;
        case 'get':      customers_get($pl); break;
        case 'inactive_list': customers_inactive_list($pl); break;
        default: fail('Action inconnue', 404);
    }
}
function customers_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $qStr = trim($_GET['q'] ?? '');
    $sql = "SELECT c.*, COUNT(o.id) AS orders_count, COALESCE(SUM(CASE WHEN o.status IN ".ENCAISSE_STATUSES." THEN o.total ELSE 0 END),0) AS total_spent,
            MAX(o.created_at) AS last_order_at
            FROM customers c LEFT JOIN orders o ON o.customer_id = c.id
            WHERE c.boutique_id=?";
    $params = [$bt['id']];
    if ($qStr !== '') { $sql .= " AND (c.name ILIKE ? OR c.phone ILIKE ? OR c.email ILIKE ?)"; $like='%'.$qStr.'%'; array_push($params,$like,$like,$like); }
    $sql .= " GROUP BY c.id ORDER BY c.created_at DESC LIMIT 500";
    ok(q($sql, $params)->fetchAll());
}
// Clients ayant deja commande au moins une fois mais plus depuis un moment -
// candidats a une relance (ex: code promo cible, voir Marketing). "Plus
// jamais commande" n'existe pas comme notion a part : c'est juste la meme
// liste avec un delai tres long.
function customers_inactive_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $days = max(1, (int)($_GET['days'] ?? 60));
    ok(q("SELECT c.*, COUNT(o.id) AS orders_count,
          COALESCE(SUM(CASE WHEN o.status IN ".ENCAISSE_STATUSES." THEN o.total ELSE 0 END),0) AS total_spent,
          MAX(o.created_at) AS last_order_at
          FROM customers c JOIN orders o ON o.customer_id = c.id
          WHERE c.boutique_id=?
          GROUP BY c.id
          HAVING MAX(o.created_at) < NOW() - (?::text || ' days')::interval
          ORDER BY MAX(o.created_at) ASC LIMIT 200", [$bt['id'], $days])->fetchAll());
}
function customers_get($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $c = q("SELECT * FROM customers WHERE id=? AND boutique_id=?", [$_GET['id'] ?? '', $bt['id']])->fetch();
    if (!$c) fail('Client introuvable', 404);
    $c['orders'] = q("SELECT * FROM orders WHERE customer_id=? ORDER BY created_at DESC", [$c['id']])->fetchAll();
    ok($c);
}

function route_contacts($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'contacts');
    switch ($action) {
        case 'roles':        contact_roles_list($pl); break;
        case 'role_create':  contact_role_create($pl); break;
        case 'list':         contacts_list($pl); break;
        case 'create':       contacts_create($pl); break;
        case 'update':       contacts_update($pl); break;
        case 'delete':       contacts_delete($pl); break;
        default: fail('Action inconnue', 404);
    }
}
function contact_roles_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM contact_roles WHERE boutique_id=? ORDER BY name", [$bt['id']])->fetchAll());
}
function contact_role_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du role est requis');
    $id = uid();
    q("INSERT INTO contact_roles (id,boutique_id,name) VALUES (?,?,?)", [$id, $bt['id'], $name]);
    ok(q("SELECT * FROM contact_roles WHERE id=?", [$id])->fetch(), 'Role cree', 201);
}
function contacts_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $sql = "SELECT c.*, r.name AS role_name FROM contacts c LEFT JOIN contact_roles r ON r.id=c.role_id WHERE c.boutique_id=?";
    $params = [$bt['id']];
    if (!empty($_GET['role_id'])) { $sql .= " AND c.role_id=?"; $params[] = $_GET['role_id']; }
    if (!empty($_GET['q'])) { $sql .= " AND c.name ILIKE ?"; $params[] = '%'.$_GET['q'].'%'; }
    $sql .= " ORDER BY c.name";
    ok(q($sql, $params)->fetchAll());
}
function contacts_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du contact est requis');
    $id = uid();
    q("INSERT INTO contacts (id,boutique_id,role_id,name,phone,email) VALUES (?,?,?,?,?,?)",
      [$id, $bt['id'], $b['role_id'] ?? null, $name, trim($b['phone'] ?? ''), trim($b['email'] ?? '')]);
    ok(q("SELECT * FROM contacts WHERE id=?", [$id])->fetch(), 'Contact ajoute', 201);
}
function contact_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM contacts WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Contact introuvable', 404);
    return $row;
}
function contacts_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = contact_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE contacts SET role_id=?, name=?, phone=?, email=? WHERE id=?",
      [$b['role_id'] ?? $row['role_id'], trim($b['name'] ?? $row['name']), trim($b['phone'] ?? $row['phone']), trim($b['email'] ?? $row['email']), $row['id']]);
    ok(q("SELECT * FROM contacts WHERE id=?", [$row['id']])->fetch(), 'Contact mis a jour');
}
function contacts_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = contact_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM contacts WHERE id=?", [$row['id']]);
    ok(null, 'Contact supprime');
}

// ============================================================
// FINANCES — Vue Globale, Livre de Compte, Frais de livraison,
// Finance Detaillee, Publicites & ROAS
// ============================================================
function route_finance($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'finance');
    switch ($action) {
        case 'overview':          finance_overview($pl); break;
        case 'accounts':          finance_accounts($pl); break;
        case 'account_create':    finance_account_create($pl); break;
        case 'account_transaction': finance_account_transaction($pl); break;
        case 'account_transfer':  finance_account_transfer($pl); break;
        case 'delivery_fees':        finance_delivery_fees($pl); break;
        case 'delivery_fee_create':  finance_delivery_fee_create($pl); break;
        case 'expenses':          finance_expenses($pl); break;
        case 'expense_create':    finance_expense_create($pl); break;
        case 'detail':             finance_detail($pl); break;
        case 'ads':                finance_ads($pl); break;
        case 'ad_expense_create':  finance_ad_expense_create($pl); break;
        case 'ad_expense_detail':  finance_ad_expense_detail($pl); break;
        case 'export':             finance_export($pl); break;
        case 'export_excel':       finance_export_excel($pl); break;
        default: fail('Action inconnue', 404);
    }
}
// Sort un CSV (ouvrable directement dans Excel/LibreOffice/Google Sheets)
// au lieu du JSON habituel - seule route de l'API qui ne repond pas via
// ok()/fail(), donc le Content-Type JSON pose en tete de fichier est
// volontairement ecrase ici avant le premier echo.
function csv_output($filename, $headers, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 : Excel affiche correctement les accents avec.
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}
// Un tableau HTML avec le bon Content-Type/extension .xls s'ouvre
// directement dans Excel/LibreOffice avec sa mise en forme (gras, bordures,
// couleurs) deja appliquee - evite d'ajouter une bibliotheque PHP (type
// PhpSpreadsheet) et son installation Composer juste pour produire un
// vrai .xlsx.
function excel_output($filename, $title, $headers, $rows) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px">';
    echo '<tr><td colspan="'.count($headers).'" style="background:#1d4ed8;color:#ffffff;font-size:15px;font-weight:bold;padding:10px">'.htmlspecialchars($title).'</td></tr>';
    echo '<tr>';
    foreach ($headers as $h) echo '<th style="background:#eff6ff;font-weight:bold;text-align:left;padding:6px;border:1px solid #cbd5e1">'.htmlspecialchars($h).'</th>';
    echo '</tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) echo '<td style="padding:6px;border:1px solid #cbd5e1">'.htmlspecialchars((string)$cell).'</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}
function finance_export_rows($bt, $period) {
    $pc = period_clause($period, 'delivered_at');
    $orders = q("SELECT * FROM orders WHERE boutique_id=? AND status='delivered' AND $pc ORDER BY delivered_at DESC", [$bt['id']])->fetchAll();
    $rows = [];
    foreach ($orders as $o) {
        $cost = (float)q("SELECT COALESCE(SUM(unit_cost*qty),0) s FROM order_items WHERE order_id=?", [$o['id']])->fetch()['s'];
        $rows[] = [$o['ref'], $o['delivered_at'], $o['customer_name'], $o['customer_phone'], $o['subtotal'],
                   $o['delivery_fee_charged'], $o['discount_amount'], $o['total'], $cost, (float)$o['total']-$cost];
    }
    return [['Reference','Livree le','Client','Telephone','Sous-total','Frais livraison','Remise','Total','Cout produits','Marge'], $rows];
}
function finance_export($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    [$headers, $rows] = finance_export_rows($bt, $_GET['period'] ?? '30d');
    csv_output('finance-detaillee-'.$bt['slug'].'.csv', $headers, $rows);
}
function finance_export_excel($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    [$headers, $rows] = finance_export_rows($bt, $_GET['period'] ?? '30d');
    excel_output('finance-detaillee-'.$bt['slug'].'.xls', 'Finance detaillee - '.$bt['name'], $headers, $rows);
}

function finance_overview($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    $pc = period_clause($period);

    $revenue = q("SELECT COALESCE(SUM(total),0) s, COUNT(*) c FROM orders
                  WHERE boutique_id=? AND status IN ".ENCAISSE_STATUSES." AND $pc", [$bt['id']])->fetch();
    $revenusEncaisses = (float)$revenue['s'];
    $nbEncaisse = (int)$revenue['c'];

    $coutProduits = (float)q("SELECT COALESCE(SUM(oi.unit_cost*oi.qty),0) s
                               FROM order_items oi JOIN orders o ON o.id=oi.order_id
                               WHERE o.boutique_id=? AND o.status='delivered' AND $pc", [$bt['id']])
                     ->fetch()['s'];
    $fraisLivraison = (float)q("SELECT COALESCE(SUM(amount),0) s FROM delivery_fees_paid
                                 WHERE boutique_id=? AND ".period_clause($period,'created_at'), [$bt['id']])->fetch()['s'];
    $fraisPub = (float)q("SELECT COALESCE(SUM(amount),0) s FROM ad_expenses
                           WHERE boutique_id=? AND ".period_clause($period,'created_at'), [$bt['id']])->fetch()['s'];
    $autresDepenses = (float)q("SELECT COALESCE(SUM(amount),0) s FROM expenses
                                 WHERE boutique_id=? AND ".period_clause($period,'created_at'), [$bt['id']])->fetch()['s'];

    $resultatNet = $revenusEncaisses - $coutProduits - $fraisLivraison - $fraisPub - $autresDepenses;

    $livraisonsFaites = (int)q("SELECT COUNT(*) c FROM orders WHERE boutique_id=? AND status='delivered' AND $pc", [$bt['id']])->fetch()['c'];

    ok([
        'revenus_encaisses'=>$revenusEncaisses, 'nb_commande_encaissee'=>$nbEncaisse,
        'livraisons_faites'=>$livraisonsFaites,
        'cout_produits'=>$coutProduits, 'frais_livraison'=>$fraisLivraison,
        'frais_pub'=>$fraisPub, 'autres_depenses'=>$autresDepenses,
        'resultat_net'=>$resultatNet,
    ]);
}

function finance_accounts($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $rows = q("SELECT * FROM accounts WHERE boutique_id=? ORDER BY created_at", [$bt['id']])->fetchAll();
    foreach ($rows as &$a) {
        $a['history'] = q("SELECT * FROM account_transactions WHERE account_id=? ORDER BY created_at DESC LIMIT 50", [$a['id']])->fetchAll();
    }
    ok($rows);
}
function finance_account_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du compte est requis');
    $id = uid();
    q("INSERT INTO accounts (id,boutique_id,name,type,balance) VALUES (?,?,?,?,?)",
      [$id, $bt['id'], $name, $b['type'] ?? 'caisse', (float)($b['balance'] ?? 0)]);
    ok(q("SELECT * FROM accounts WHERE id=?", [$id])->fetch(), 'Compte cree', 201);
}
function account_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM accounts WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Compte introuvable', 404);
    return $row;
}
function finance_account_transaction($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $acc = account_owned($b['account_id'] ?? '', $bt['id']);
    $type = $b['type'] ?? '';
    if (!in_array($type, ['in','out'], true)) fail('Type invalide');
    $amount = (float)($b['amount'] ?? 0);
    if ($amount <= 0) fail('Montant invalide');
    $delta = $type === 'in' ? $amount : -$amount;
    q("UPDATE accounts SET balance = balance + ? WHERE id=?", [$delta, $acc['id']]);
    q("INSERT INTO account_transactions (id,account_id,boutique_id,type,amount,note) VALUES (?,?,?,?,?,?)",
      [uid(), $acc['id'], $bt['id'], $type, $amount, trim($b['note'] ?? '')]);
    ok(q("SELECT * FROM accounts WHERE id=?", [$acc['id']])->fetch(), 'Mouvement enregistre');
}
function finance_account_transfer($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $from = account_owned($b['from_account_id'] ?? '', $bt['id']);
    $to = account_owned($b['to_account_id'] ?? '', $bt['id']);
    $amount = (float)($b['amount'] ?? 0);
    if ($amount <= 0) fail('Montant invalide');
    if ($from['id'] === $to['id']) fail('Choisissez deux comptes differents');
    $note = trim($b['note'] ?? '').' (transfert '.$from['name'].' -> '.$to['name'].')';
    $pdo = db(); $pdo->beginTransaction();
    try {
        q("UPDATE accounts SET balance = balance - ? WHERE id=?", [$amount, $from['id']]);
        q("UPDATE accounts SET balance = balance + ? WHERE id=?", [$amount, $to['id']]);
        q("INSERT INTO account_transactions (id,account_id,boutique_id,type,amount,note) VALUES (?,?,?,?,?,?)",
          [uid(), $from['id'], $bt['id'], 'out', $amount, $note]);
        q("INSERT INTO account_transactions (id,account_id,boutique_id,type,amount,note) VALUES (?,?,?,?,?,?)",
          [uid(), $to['id'], $bt['id'], 'in', $amount, $note]);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); fail('Transfert impossible', 500); }
    ok(null, 'Transfert effectue');
}

function finance_delivery_fees($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    $rows = q("SELECT dfp.*, o.ref AS order_ref, dp.name AS delivery_person_name
               FROM delivery_fees_paid dfp
               LEFT JOIN orders o ON o.id = dfp.order_id
               LEFT JOIN delivery_persons dp ON dp.id = dfp.delivery_person_id
               WHERE dfp.boutique_id=? AND ".period_clause($period,'dfp.created_at')."
               ORDER BY dfp.created_at DESC", [$bt['id']])->fetchAll();
    $totalMonth = (float)q("SELECT COALESCE(SUM(amount),0) s FROM delivery_fees_paid
                             WHERE boutique_id=? AND created_at >= date_trunc('month', NOW())", [$bt['id']])->fetch()['s'];
    $total = (float)q("SELECT COALESCE(SUM(amount),0) s FROM delivery_fees_paid WHERE boutique_id=?", [$bt['id']])->fetch()['s'];
    ok(['rows'=>$rows, 'total_month'=>$totalMonth, 'total_all'=>$total, 'count'=>count($rows)]);
}
function finance_delivery_fee_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $amount = (float)($b['amount'] ?? 0);
    if ($amount <= 0) fail('Montant invalide');
    if (!empty($b['order_id'])) order_owned($b['order_id'], $bt['id']);
    if (!empty($b['delivery_person_id'])) {
        $dp = q("SELECT id FROM delivery_persons WHERE id=? AND boutique_id=?", [$b['delivery_person_id'], $bt['id']])->fetch();
        if (!$dp) fail('Livreur introuvable', 404);
    }
    $id = uid();
    q("INSERT INTO delivery_fees_paid (id,boutique_id,order_id,delivery_person_id,amount,note,account_id,paid_at)
       VALUES (?,?,?,?,?,?,?,COALESCE(?,CURRENT_DATE))",
      [$id, $bt['id'], $b['order_id'] ?? null, $b['delivery_person_id'] ?? null, $amount,
       trim($b['note'] ?? ''), $b['account_id'] ?? null, $b['paid_at'] ?? null]);
    if (!empty($b['account_id'])) {
        account_owned($b['account_id'], $bt['id']);
        q("UPDATE accounts SET balance = balance - ? WHERE id=?", [$amount, $b['account_id']]);
        q("INSERT INTO account_transactions (id,account_id,boutique_id,type,amount,note) VALUES (?,?,?,?,?,?)",
          [uid(), $b['account_id'], $bt['id'], 'out', $amount, 'Frais de livraison']);
    }
    ok(q("SELECT * FROM delivery_fees_paid WHERE id=?", [$id])->fetch(), 'Frais enregistre', 201);
}

function finance_expenses($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    ok(q("SELECT * FROM expenses WHERE boutique_id=? AND ".period_clause($period,'created_at')."
          ORDER BY created_at DESC", [$bt['id']])->fetchAll());
}
function finance_expense_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $label = trim($b['label'] ?? '');
    $amount = (float)($b['amount'] ?? 0);
    if ($label === '' || $amount <= 0) fail('Libelle et montant requis');
    $id = uid();
    q("INSERT INTO expenses (id,boutique_id,label,category,amount,account_id,expense_date)
       VALUES (?,?,?,?,?,?,COALESCE(?,CURRENT_DATE))",
      [$id, $bt['id'], $label, trim($b['category'] ?? ''), $amount, $b['account_id'] ?? null, $b['expense_date'] ?? null]);
    if (!empty($b['account_id'])) {
        account_owned($b['account_id'], $bt['id']);
        q("UPDATE accounts SET balance = balance - ? WHERE id=?", [$amount, $b['account_id']]);
        q("INSERT INTO account_transactions (id,account_id,boutique_id,type,amount,note) VALUES (?,?,?,?,?,?)",
          [uid(), $b['account_id'], $bt['id'], 'out', $amount, 'Depense: '.$label]);
    }
    ok(q("SELECT * FROM expenses WHERE id=?", [$id])->fetch(), 'Depense enregistree', 201);
}

function finance_detail($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    $pc = period_clause($period);
    $orders = q("SELECT * FROM orders WHERE boutique_id=? AND status='delivered' AND $pc ORDER BY delivered_at DESC", [$bt['id']])->fetchAll();
    $rows = [];
    $totalRevenue = 0; $totalCost = 0;
    foreach ($orders as $o) {
        $cost = (float)q("SELECT COALESCE(SUM(unit_cost*qty),0) s FROM order_items WHERE order_id=?", [$o['id']])->fetch()['s'];
        $margin = (float)$o['total'] - $cost;
        $totalRevenue += (float)$o['total']; $totalCost += $cost;
        $rows[] = ['ref'=>$o['ref'], 'customer_name'=>$o['customer_name'], 'total'=>$o['total'], 'cost'=>$cost, 'margin'=>$margin, 'delivered_at'=>$o['delivered_at']];
    }
    ok(['rows'=>$rows, 'total_revenue'=>$totalRevenue, 'total_cost'=>$totalCost, 'total_margin'=>$totalRevenue-$totalCost]);
}

function finance_ads($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    $pcAd = period_clause($period, 'created_at');
    $pcOrder = period_clause($period);
    $depenseTotale = (float)q("SELECT COALESCE(SUM(amount),0) s FROM ad_expenses WHERE boutique_id=? AND $pcAd", [$bt['id']])->fetch()['s'];

    // Ici $pcAd (non prefixe) serait ambigu : products a aussi une colonne
    // created_at, donc on qualifie explicitement celle de ad_expenses.
    $pcAdJoined = period_clause($period, 'ae.created_at');
    // Quand une meme depense couvre plusieurs produits (carrousel, promo
    // groupee), son montant est reparti a parts egales entre eux plutot que
    // compte en entier pour chacun - sinon la somme des depenses "par
    // produit" depasserait la depense totale reellement payee.
    $byProduct = q("SELECT aep.product_id, p.name AS product_name, COALESCE(SUM(ae.amount / cnt.n), 0) AS spend
                    FROM ad_expense_products aep
                    JOIN ad_expenses ae ON ae.id = aep.ad_expense_id
                    JOIN (SELECT ad_expense_id, COUNT(*)::float AS n FROM ad_expense_products GROUP BY ad_expense_id) cnt
                         ON cnt.ad_expense_id = aep.ad_expense_id
                    LEFT JOIN products p ON p.id = aep.product_id
                    WHERE ae.boutique_id=? AND $pcAdJoined
                    GROUP BY aep.product_id, p.name", [$bt['id']])->fetchAll();
    foreach ($byProduct as &$row) {
        $revenu = (float)q("SELECT COALESCE(SUM(oi.unit_price*oi.qty),0) s FROM order_items oi
                             JOIN orders o ON o.id = oi.order_id
                             WHERE o.boutique_id=? AND o.status='delivered' AND oi.product_id=? AND $pcOrder",
                            [$bt['id'], $row['product_id']])->fetch()['s'];
        $row['revenue'] = $revenu;
        $row['roas'] = $row['spend'] > 0 ? round($revenu / $row['spend'], 2) : null;
    }

    $byCampaign = q("SELECT campaign_name, COALESCE(SUM(amount),0) AS spend
                     FROM ad_expenses WHERE boutique_id=? AND campaign_name IS NOT NULL AND campaign_name<>'' AND $pcAd
                     GROUP BY campaign_name", [$bt['id']])->fetchAll();
    foreach ($byCampaign as &$row) {
        $revenu = (float)q("SELECT COALESCE(SUM(total),0) s FROM orders
                             WHERE boutique_id=? AND status='delivered' AND utm_campaign=? AND $pcOrder",
                            [$bt['id'], $row['campaign_name']])->fetch()['s'];
        $row['revenue'] = $revenu;
        $row['roas'] = $row['spend'] > 0 ? round($revenu / $row['spend'], 2) : null;
    }

    $revenuAttribueTotal = array_sum(array_column($byProduct, 'revenue')) + array_sum(array_column($byCampaign, 'revenue'));
    $roasGlobal = $depenseTotale > 0 ? round($revenuAttribueTotal / $depenseTotale, 2) : null;

    // product_slug permet au tableau de bord de reconstituer le lien exact
    // de la campagne (store/index.html?...&p=slug&utm_campaign=...) sans
    // avoir a le retaper - voir "Dernieres depenses" cote frontend.
    $recent = q("SELECT ae.*, p.slug AS product_slug,
                 (SELECT COUNT(*) FROM ad_expense_products WHERE ad_expense_id=ae.id) AS product_count
                 FROM ad_expenses ae LEFT JOIN products p ON p.id = ae.product_id
                 WHERE ae.boutique_id=? AND $pcAdJoined ORDER BY ae.created_at DESC LIMIT 50", [$bt['id']])->fetchAll();

    ok([
        'depense_totale'=>$depenseTotale, 'revenu_attribue'=>$revenuAttribueTotal, 'roas_global'=>$roasGlobal,
        'by_product'=>$byProduct, 'by_campaign'=>$byCampaign, 'recent'=>$recent,
    ]);
}
function finance_ad_expense_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $amount = (float)($b['amount'] ?? 0);
    if ($amount <= 0) fail('Montant invalide');
    // Accepte plusieurs produits (carrousel, promo groupee) ; product_id
    // reste rempli seulement quand il n'y en a qu'un, par compatibilite
    // avec l'ancien affichage - ad_expense_products est la vraie source
    // pour le ROAS par produit des qu'il y en a plusieurs.
    $productIds = array_values(array_unique(array_filter((array)($b['product_ids'] ?? []))));
    if (!$productIds && !empty($b['product_id'])) $productIds = [$b['product_id']];
    foreach ($productIds as $pid) { product_owned($pid, $bt['id']); }
    $id = uid();
    $singleProductId = count($productIds) === 1 ? $productIds[0] : null;
    q("INSERT INTO ad_expenses (id,boutique_id,campaign_name,product_id,amount,spend_date)
       VALUES (?,?,?,?,?,COALESCE(?,CURRENT_DATE))",
      [$id, $bt['id'], trim($b['campaign_name'] ?? ''), $singleProductId, $amount, $b['spend_date'] ?? null]);
    foreach ($productIds as $pid) {
        q("INSERT INTO ad_expense_products (ad_expense_id, product_id) VALUES (?,?) ON CONFLICT DO NOTHING", [$id, $pid]);
    }
    ok(q("SELECT * FROM ad_expenses WHERE id=?", [$id])->fetch(), 'Depense publicitaire enregistree', 201);
}

// Detail par produit pour UNE depense precise (ex: un carrousel a 3
// articles) - contrairement a finance_ads()?action=ads (qui agrege par
// produit sur TOUTES les depenses de la periode), on isole ici uniquement
// les produits lies a ce groupe, avec sa propre part du budget (partagee a
// parts egales) et son propre revenu/ROAS.
function finance_ad_expense_detail($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $expense = q("SELECT * FROM ad_expenses WHERE id=? AND boutique_id=?", [$_GET['id'] ?? '', $bt['id']])->fetch();
    if (!$expense) fail('Depense introuvable', 404);
    $products = q("SELECT p.id, p.name FROM ad_expense_products aep JOIN products p ON p.id=aep.product_id
                   WHERE aep.ad_expense_id=? ORDER BY p.name", [$expense['id']])->fetchAll();
    $n = max(1, count($products));
    $sharePerProduct = (float)$expense['amount'] / $n;
    $period = $_GET['period'] ?? '30d';
    $pcOrder = period_clause($period);
    $rows = [];
    foreach ($products as $p) {
        $revenu = (float)q("SELECT COALESCE(SUM(oi.unit_price*oi.qty),0) s FROM order_items oi
                             JOIN orders o ON o.id = oi.order_id
                             WHERE o.boutique_id=? AND o.status='delivered' AND oi.product_id=? AND $pcOrder",
                            [$bt['id'], $p['id']])->fetch()['s'];
        $rows[] = [
            'product_id' => $p['id'], 'product_name' => $p['name'], 'spend_share' => $sharePerProduct,
            'revenue' => $revenu, 'roas' => $sharePerProduct > 0 ? round($revenu / $sharePerProduct, 2) : null,
        ];
    }
    ok(['campaign_name' => $expense['campaign_name'], 'total_amount' => (float)$expense['amount'], 'products' => $rows]);
}

// ============================================================
// ANALYTIQUE — rapports de ventes et activite en direct
// ============================================================
function route_analytics($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'analytics');
    switch ($action) {
        case 'report':   analytics_report($pl); break;
        case 'live':     analytics_live($pl); break;
        case 'activity_log': analytics_activity_log($pl); break;
        case 'export':       analytics_export($pl); break;
        case 'export_excel': analytics_export_excel($pl); break;
        case 'revenue_by_month': analytics_revenue_by_month($pl); break;
        default: fail('Action inconnue', 404);
    }
}
// Ventes de LA BOUTIQUE regroupees par mois - meme principe et meme forme
// de reponse que admin_revenue_by_month() (qui, lui, agrege les revenus
// d'abonnement de toute la plateforme, pas les ventes d'une boutique) :
// le graphique/tableau cote tableau de bord marchand reutilise le meme
// composant de rendu (voir renderRevenueChart() dans dashboard/index.html).
function analytics_revenue_by_month($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $rows = q("SELECT TO_CHAR(created_at, 'YYYY-MM') AS month, COALESCE(SUM(total),0) AS revenue
               FROM orders WHERE boutique_id=? AND status IN ".ENCAISSE_STATUSES."
               GROUP BY month ORDER BY month", [$bt['id']])->fetchAll();
    ok($rows);
}
function analytics_export_rows($bt, $period) {
    $pc = period_clause($period);
    $sales = q("SELECT DATE(created_at) d, COUNT(*) commandes, COALESCE(SUM(total),0) ventes
                FROM orders WHERE boutique_id=? AND status<>'cancelled' AND $pc
                GROUP BY DATE(created_at) ORDER BY d", [$bt['id']])->fetchAll();
    return [['Date','Commandes','Ventes'], array_map(fn($r) => [$r['d'], $r['commandes'], $r['ventes']], $sales)];
}
function analytics_export($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    [$headers, $rows] = analytics_export_rows($bt, $_GET['period'] ?? '30d');
    csv_output('rapport-ventes-'.$bt['slug'].'.csv', $headers, $rows);
}
function analytics_export_excel($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    [$headers, $rows] = analytics_export_rows($bt, $_GET['period'] ?? '30d');
    excel_output('rapport-ventes-'.$bt['slug'].'.xls', 'Rapport de ventes - '.$bt['name'], $headers, $rows);
}

function analytics_report($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    $pc = period_clause($period);

    $kpi = q("SELECT COALESCE(SUM(total),0) ventes_brutes, COUNT(*) commandes
              FROM orders WHERE boutique_id=? AND status<>'cancelled' AND $pc", [$bt['id']])->fetch();
    $ventesBrutes = (float)$kpi['ventes_brutes'];
    $commandes = (int)$kpi['commandes'];
    $panierMoyen = $commandes > 0 ? round($ventesBrutes / $commandes, 2) : 0;
    $livrees = (int)q("SELECT COUNT(*) c FROM orders WHERE boutique_id=? AND status='delivered' AND $pc", [$bt['id']])->fetch()['c'];
    $retours = (float)q("SELECT COALESCE(SUM(total),0) s FROM orders WHERE boutique_id=? AND status='refused' AND $pc", [$bt['id']])->fetch()['s'];
    $fraisLivraisonClients = (float)q("SELECT COALESCE(SUM(delivery_fee_charged),0) s FROM orders WHERE boutique_id=? AND status<>'cancelled' AND $pc", [$bt['id']])->fetch()['s'];
    $ventesNettes = $ventesBrutes - $retours;

    $salesSeries = q("SELECT DATE(created_at) d, COALESCE(SUM(total),0) ventes,
                       COALESCE(SUM(CASE WHEN status IN ".ENCAISSE_STATUSES." THEN total ELSE 0 END),0) encaissements
                       FROM orders WHERE boutique_id=? AND status<>'cancelled' AND $pc
                       GROUP BY DATE(created_at) ORDER BY d", [$bt['id']])->fetchAll();

    $visitSeries = q("SELECT DATE(created_at) d, COUNT(*) vues, COUNT(DISTINCT session_id) uniques
                       FROM visits WHERE boutique_id=? AND ".period_clause($period,'created_at')."
                       GROUP BY DATE(created_at) ORDER BY d", [$bt['id']])->fetchAll();

    $bestSellers = q("SELECT oi.product_id, oi.product_name, SUM(oi.qty) qty, SUM(oi.unit_price*oi.qty) revenue
                       FROM order_items oi JOIN orders o ON o.id=oi.order_id
                       WHERE o.boutique_id=? AND o.status<>'cancelled' AND $pc
                       GROUP BY oi.product_id, oi.product_name ORDER BY qty DESC LIMIT 10", [$bt['id']])->fetchAll();

    $utm = q("SELECT utm_source, utm_campaign, COUNT(*) commandes, COALESCE(SUM(total),0) ventes
              FROM orders WHERE boutique_id=? AND (utm_source<>'' OR utm_campaign<>'') AND $pc
              GROUP BY utm_source, utm_campaign", [$bt['id']])->fetchAll();

    ok([
        'ventes_brutes'=>$ventesBrutes, 'commandes'=>$commandes, 'panier_moyen'=>$panierMoyen, 'livrees'=>$livrees,
        'decomposition'=>[
            'ventes_brutes'=>$ventesBrutes, 'remises'=>0, 'retours'=>$retours, 'ventes_nettes'=>$ventesNettes,
            'frais_livraison'=>$fraisLivraisonClients, 'taxes'=>0, 'total'=>$ventesNettes+$fraisLivraisonClients,
        ],
        'sales_series'=>$salesSeries, 'visit_series'=>$visitSeries, 'best_sellers'=>$bestSellers, 'utm'=>$utm,
    ]);
}

function analytics_live($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $today = "DATE(created_at) = CURRENT_DATE";
    $cmdToday = (int)q("SELECT COUNT(*) c FROM orders WHERE boutique_id=? AND $today", [$bt['id']])->fetch()['c'];
    $revToday = (float)q("SELECT COALESCE(SUM(total),0) s FROM orders WHERE boutique_id=? AND status IN ".ENCAISSE_STATUSES." AND $today", [$bt['id']])->fetch()['s'];
    $panierMoyen = $cmdToday > 0 ? round($revToday / $cmdToday, 2) : 0;
    $recent = q("SELECT * FROM orders WHERE boutique_id=? ORDER BY created_at DESC LIMIT 10", [$bt['id']])->fetchAll();
    $activity = q("SELECT * FROM activity_log WHERE boutique_id=? ORDER BY created_at DESC LIMIT 20", [$bt['id']])->fetchAll();
    ok(['commandes_aujourdhui'=>$cmdToday, 'revenus_aujourdhui'=>$revToday, 'panier_moyen'=>$panierMoyen,
        'commandes_recentes'=>$recent, 'activite'=>$activity, 'server_time'=>date('c')]);
}

// Historique complet (qui a fait quoi, quand) - reserve au proprietaire et
// aux membres 'admin' de la boutique, comme l'equipe et les parametres :
// ca revele l'activite de chaque collegue, pas seulement des chiffres.
function analytics_activity_log($pl) {
    $bt = require_boutique_admin($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM activity_log WHERE boutique_id=? ORDER BY created_at DESC LIMIT 300", [$bt['id']])->fetchAll());
}

// ============================================================
// MARKETING — abonnes newsletter et messages de contact captes sur la
// vitrine publique
// ============================================================
function route_marketing($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'marketing');
    switch ($action) {
        case 'newsletter':     marketing_newsletter($pl); break;
        case 'messages':       marketing_messages($pl); break;
        case 'message_mark_read': marketing_message_mark_read($pl); break;
        case 'promo_list':     marketing_promo_list($pl); break;
        case 'promo_create':   marketing_promo_create($pl); break;
        case 'promo_update':   marketing_promo_update($pl); break;
        case 'promo_delete':   marketing_promo_delete($pl); break;
        case 'review_list':    marketing_review_list($pl); break;
        case 'review_moderate':marketing_review_moderate($pl); break;
        case 'review_delete':  marketing_review_delete($pl); break;
        case 'promotion_list':   marketing_promotion_list($pl); break;
        case 'promotion_create': marketing_promotion_create($pl); break;
        case 'promotion_update': marketing_promotion_update($pl); break;
        case 'promotion_delete': marketing_promotion_delete($pl); break;
        default: fail('Action inconnue', 404);
    }
}
function marketing_promo_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT pc.*, COALESCE((SELECT string_agg(phone, ', ' ORDER BY phone) FROM promo_code_customers WHERE promo_code_id=pc.id), '') AS target_phones
          FROM promo_codes pc WHERE pc.boutique_id=? ORDER BY pc.created_at DESC", [$bt['id']])->fetchAll());
}
// Remplace entierement la liste des numeros cibles par un code - une liste
// vide rend le code ouvert a tous (voir find_active_promo()).
function set_promo_targets($promoId, $phones) {
    $phones = array_values(array_unique(array_filter(array_map('trim', (array)$phones))));
    q("DELETE FROM promo_code_customers WHERE promo_code_id=?", [$promoId]);
    foreach ($phones as $phone) {
        q("INSERT INTO promo_code_customers (promo_code_id, phone) VALUES (?,?) ON CONFLICT DO NOTHING", [$promoId, $phone]);
    }
}
// Limite le nombre de codes ACTIFS simultanement selon le plan du
// proprietaire (voir PLANS.promo_limit) - un code desactive ou expire ne
// compte plus dans la limite.
function assert_promo_limit_not_reached($bt, $userId) {
    $plan = q("SELECT plan FROM users WHERE id=?", [$userId])->fetchColumn() ?: 'starter';
    $limit = PLANS[$plan]['promo_limit'] ?? 1;
    $count = (int)q("SELECT COUNT(*) c FROM promo_codes WHERE boutique_id=? AND active=1
                      AND (expires_at IS NULL OR expires_at > NOW())", [$bt['id']])->fetch()['c'];
    if ($count >= $limit) {
        fail('Limite de codes promo actifs atteinte pour votre plan ('.$limit.'). Passez a un plan superieur ou desactivez un code existant.', 403);
    }
}
function marketing_promo_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    assert_promo_limit_not_reached($bt, $pl['sub']);
    $code = trim($b['code'] ?? '');
    $type = in_array($b['type'] ?? '', ['percent','amount'], true) ? $b['type'] : 'percent';
    $value = (float)($b['value'] ?? 0);
    if ($code === '') fail('Le code est requis');
    if ($value <= 0) fail('La valeur doit etre superieure a 0');
    if ($type === 'percent' && $value > 100) fail('Un pourcentage ne peut pas depasser 100');
    if (q("SELECT 1 FROM promo_codes WHERE boutique_id=? AND UPPER(code)=UPPER(?)", [$bt['id'], $code])->fetch()) {
        fail('Ce code existe deja pour cette boutique');
    }
    $id = uid();
    q("INSERT INTO promo_codes (id,boutique_id,code,type,value,max_uses,expires_at,active) VALUES (?,?,?,?,?,?,?,1)",
      [$id, $bt['id'], strtoupper($code), $type, $value,
       isset($b['max_uses']) && $b['max_uses'] !== '' ? (int)$b['max_uses'] : null,
       isset($b['expires_at']) && $b['expires_at'] !== '' ? $b['expires_at'] : null]);
    set_promo_targets($id, $b['target_phones'] ?? []);
    log_activity($bt['id'], 'Code promo cree: '.strtoupper($code), $pl['sub']);
    ok(q("SELECT * FROM promo_codes WHERE id=?", [$id])->fetch(), 'Code promo cree', 201);
}
function promo_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM promo_codes WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Code promo introuvable', 404);
    return $row;
}
function marketing_promo_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $promo = promo_owned($b['id'] ?? '', $bt['id']);
    $wasActive = (bool)$promo['active'];
    $nowActive = isset($b['active']) ? (bool)!!$b['active'] : $wasActive;
    if (!$wasActive && $nowActive) assert_promo_limit_not_reached($bt, $pl['sub']);
    q("UPDATE promo_codes SET type=?, value=?, max_uses=?, expires_at=?, active=? WHERE id=?",
      [in_array($b['type'] ?? '', ['percent','amount'], true) ? $b['type'] : $promo['type'],
       isset($b['value']) && $b['value'] !== '' ? (float)$b['value'] : $promo['value'],
       isset($b['max_uses']) && $b['max_uses'] !== '' ? (int)$b['max_uses'] : null,
       isset($b['expires_at']) && $b['expires_at'] !== '' ? $b['expires_at'] : null,
       (int)$nowActive, $promo['id']]);
    if (array_key_exists('target_phones', $b)) set_promo_targets($promo['id'], $b['target_phones']);
    ok(q("SELECT * FROM promo_codes WHERE id=?", [$promo['id']])->fetch(), 'Code promo mis a jour');
}
function marketing_promo_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $promo = promo_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM promo_code_customers WHERE promo_code_id=?", [$promo['id']]);
    q("DELETE FROM promo_codes WHERE id=?", [$promo['id']]);
    ok(null, 'Code promo supprime');
}
function marketing_review_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $rows = q("SELECT r.*, p.name AS product_name FROM product_reviews r
               LEFT JOIN products p ON p.id = r.product_id
               WHERE r.boutique_id=? ORDER BY r.created_at DESC LIMIT 300", [$bt['id']])->fetchAll();
    ok($rows);
}
function marketing_review_moderate($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = q("SELECT id FROM product_reviews WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']])->fetch();
    if (!$row) fail('Avis introuvable', 404);
    $status = $b['status'] ?? '';
    if (!in_array($status, ['approved','rejected','pending'], true)) fail('Statut invalide');
    q("UPDATE product_reviews SET status=? WHERE id=?", [$status, $row['id']]);
    ok(null, 'Avis mis a jour');
}
function marketing_review_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = q("SELECT id FROM product_reviews WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']])->fetch();
    if (!$row) fail('Avis introuvable', 404);
    q("DELETE FROM product_reviews WHERE id=?", [$row['id']]);
    ok(null, 'Avis supprime');
}

// ============================================================
// PROMOTIONS PRODUIT — reduction automatiquement visible sur le prix des
// produits choisis (contrairement aux codes promo, aucune saisie du client
// n'est necessaire). Voir apply_active_promotion()/effective_unit_price()
// dans le module shop pour la partie affichage/calcul cote vitrine.
// ============================================================
function marketing_promotion_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $rows = q("SELECT * FROM product_promotions WHERE boutique_id=? ORDER BY created_at DESC", [$bt['id']])->fetchAll();
    foreach ($rows as &$pr) {
        $pr['products'] = q("SELECT p.id, p.name FROM product_promotion_items ppi
                              JOIN products p ON p.id = ppi.product_id
                              WHERE ppi.promotion_id=? ORDER BY p.name", [$pr['id']])->fetchAll();
    }
    ok($rows);
}
function set_promotion_products($promotionId, $productIds, $boutiqueId) {
    $productIds = array_values(array_unique(array_filter((array)$productIds)));
    q("DELETE FROM product_promotion_items WHERE promotion_id=?", [$promotionId]);
    foreach ($productIds as $pid) {
        product_owned($pid, $boutiqueId); // 404 si le produit n'appartient pas a cette boutique
        q("INSERT INTO product_promotion_items (promotion_id, product_id) VALUES (?,?) ON CONFLICT DO NOTHING", [$promotionId, $pid]);
    }
}
function marketing_promotion_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $type = in_array($b['type'] ?? '', ['percent','amount'], true) ? $b['type'] : 'percent';
    $value = (float)($b['value'] ?? 0);
    $productIds = (array)($b['product_ids'] ?? []);
    if ($value <= 0) fail('La valeur doit etre superieure a 0');
    if ($type === 'percent' && $value > 100) fail('Un pourcentage ne peut pas depasser 100');
    if (!$productIds) fail('Selectionnez au moins un produit');
    $id = uid();
    q("INSERT INTO product_promotions (id,boutique_id,type,value,starts_at,expires_at,active) VALUES (?,?,?,?,?,?,1)",
      [$id, $bt['id'], $type, $value,
       isset($b['starts_at']) && $b['starts_at'] !== '' ? $b['starts_at'] : null,
       isset($b['expires_at']) && $b['expires_at'] !== '' ? $b['expires_at'] : null]);
    set_promotion_products($id, $productIds, $bt['id']);
    log_activity($bt['id'], 'Promotion produit creee ('.count($productIds).' produit(s))', $pl['sub']);
    ok(q("SELECT * FROM product_promotions WHERE id=?", [$id])->fetch(), 'Promotion creee', 201);
}
function promotion_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM product_promotions WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Promotion introuvable', 404);
    return $row;
}
function marketing_promotion_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $promo = promotion_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE product_promotions SET type=?, value=?, starts_at=?, expires_at=?, active=? WHERE id=?",
      [in_array($b['type'] ?? '', ['percent','amount'], true) ? $b['type'] : $promo['type'],
       isset($b['value']) && $b['value'] !== '' ? (float)$b['value'] : $promo['value'],
       isset($b['starts_at']) && $b['starts_at'] !== '' ? $b['starts_at'] : null,
       isset($b['expires_at']) && $b['expires_at'] !== '' ? $b['expires_at'] : null,
       isset($b['active']) ? (int)!!$b['active'] : $promo['active'], $promo['id']]);
    if (array_key_exists('product_ids', $b)) set_promotion_products($promo['id'], $b['product_ids'], $bt['id']);
    ok(q("SELECT * FROM product_promotions WHERE id=?", [$promo['id']])->fetch(), 'Promotion mise a jour');
}
function marketing_promotion_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $promo = promotion_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM product_promotion_items WHERE promotion_id=?", [$promo['id']]);
    q("DELETE FROM product_promotions WHERE id=?", [$promo['id']]);
    ok(null, 'Promotion supprimee');
}
function marketing_newsletter($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM newsletter_subscribers WHERE boutique_id=? ORDER BY created_at DESC", [$bt['id']])->fetchAll());
}
function marketing_messages($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM contact_messages WHERE boutique_id=? ORDER BY created_at DESC", [$bt['id']])->fetchAll());
}
function marketing_message_mark_read($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = q("SELECT id FROM contact_messages WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']])->fetch();
    if (!$row) fail('Introuvable', 404);
    q("UPDATE contact_messages SET is_read=1 WHERE id=?", [$row['id']]);
    ok(null, 'Marque comme lu');
}

// ============================================================
// EQUIPE — inviter des collegues sur une boutique (roles : admin, manager,
// livreur, closeuse, comptable). Seuls le proprietaire et les membres
// 'admin' peuvent gerer l'equipe (require_boutique_admin) ; les autres
// roles ont pour l'instant le meme acces au reste de la boutique que
// l'equipe existante - aucune restriction fine par page n'est encore
// appliquee cote serveur au-dela des parametres/de l'equipe elle-meme.
// ============================================================
function route_team($action) {
    $pl = owner_auth();
    switch ($action) {
        case 'list':   team_list($pl); break;
        case 'invite': team_invite($pl); break;
        case 'remove': team_remove($pl); break;
        case 'accept_invite': team_accept_invite($pl); break;
        default: fail('Action inconnue', 404);
    }
}

function team_invite_link($token, $pageUrl) {
    $pageUrl = trim($pageUrl);
    if ($pageUrl !== '' && preg_match('#^https?://#i', $pageUrl)) {
        $base = rtrim(strtok($pageUrl, '?#'), '/');
    } else {
        $base = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
    }
    return $base.'?team_invite='.$token; // voir le commentaire dans auth_verify_link()
}

function team_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $rows = q("SELECT bm.id, bm.email, bm.role, bm.status, bm.created_at, u.full_name
               FROM boutique_members bm LEFT JOIN users u ON u.id=bm.user_id
               WHERE bm.boutique_id=? ORDER BY bm.created_at", [$bt['id']])->fetchAll();
    $owner = q("SELECT email, full_name FROM users WHERE id=?", [$bt['owner_user_id']])->fetch();
    array_unshift($rows, ['id'=>null, 'email'=>$owner['email'] ?? '', 'full_name'=>$owner['full_name'] ?? '', 'role'=>'owner', 'status'=>'active', 'created_at'=>null]);
    ok($rows);
}

function team_invite($pl) {
    $b = body();
    $bt = require_boutique_admin($b['boutique_id'] ?? '', $pl['sub']);
    $email = strtolower(trim($b['email'] ?? ''));
    $role = $b['role'] ?? 'manager';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse email invalide');
    if (!in_array($role, TEAM_ROLES, true)) fail('Role invalide');
    $existing = q("SELECT id FROM boutique_members WHERE boutique_id=? AND email=?", [$bt['id'], $email])->fetch();
    if ($existing) fail('Ce membre est deja invite sur cette boutique', 409);
    // Pour un livreur, on peut le lier des l'invitation a une fiche de la
    // page Livraisons (voir delivery_persons) : c'est ce lien qui lui
    // permettra de ne voir que ses propres livraisons assignees.
    $deliveryPersonId = null;
    if ($role === 'livreur' && !empty($b['delivery_person_id'])) {
        $dp = q("SELECT id FROM delivery_persons WHERE id=? AND boutique_id=?", [$b['delivery_person_id'], $bt['id']])->fetch();
        if ($dp) $deliveryPersonId = $dp['id'];
    }
    $token = bin2hex(random_bytes(24));
    $id = uid();
    q("INSERT INTO boutique_members (id,boutique_id,email,role,invite_token,status,delivery_person_id) VALUES (?,?,?,?,?,'pending',?)",
      [$id, $bt['id'], $email, $role, $token, $deliveryPersonId]);
    $link = team_invite_link($token, $b['page_url'] ?? '');
    send_email($email, 'Invitation a rejoindre '.$bt['name'].' sur MYBOUTIK',
        "Vous avez ete invite(e) a gerer la boutique ".$bt['name']." avec le role \"$role\".\n".
        "Connectez-vous (ou creez un compte avec cette meme adresse email) puis ouvrez ce lien :\n".$link);
    $data = ['id'=>$id];
    if (APP_ENV === 'development') $data['invite_link_dev_only'] = $link;
    ok($data, 'Invitation envoyee', 201);
}

function team_remove($pl) {
    $b = body();
    $bt = require_boutique_admin($b['boutique_id'] ?? '', $pl['sub']);
    q("DELETE FROM boutique_members WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']]);
    ok(null, 'Membre retire');
}

function team_accept_invite($pl) {
    $token = bg('token');
    if (!$token) fail('Token manquant');
    $member = q("SELECT * FROM boutique_members WHERE invite_token=? AND status='pending'", [$token])->fetch();
    if (!$member) fail('Invitation invalide ou deja utilisee', 404);
    $user = q("SELECT email FROM users WHERE id=?", [$pl['sub']])->fetch();
    if (strtolower($user['email']) !== strtolower($member['email'])) {
        fail('Cette invitation est destinee a une autre adresse email', 403);
    }
    q("UPDATE boutique_members SET user_id=?, status='active', invite_token=NULL WHERE id=?", [$pl['sub'], $member['id']]);
    ok(null, 'Invitation acceptee');
}

// ============================================================
// ABONNEMENT — plans/limites de boutiques. Aucune passerelle de paiement
// automatique : choisir un plan cree une demande verifiee manuellement par
// l'operateur de MYBOUTIK (voir route_admin()).
// ============================================================
function route_billing($action) {
    $pl = owner_auth();
    switch ($action) {
        case 'plans':     billing_plans($pl); break;
        case 'subscribe': billing_subscribe($pl); break;
        case 'affiliate_info':          billing_affiliate_info($pl); break;
        case 'affiliate_request_payout':billing_affiliate_request_payout($pl); break;
        default: fail('Action inconnue', 404);
    }
}

// Eligible au mois gratuit Starter uniquement si ce compte n'a JAMAIS eu
// un seul abonnement approuve (tous plans confondus) - un compte qui a
// deja ete Pro/Premium (ou meme Starter payant) et revient sur Starter
// n'a plus droit au mois gratuit, meme si son plan actuel est redevenu
// 'starter' entre-temps (voir billing_subscribe()).
function user_ever_had_approved_subscription($userId) {
    return (bool) q("SELECT 1 FROM subscription_requests WHERE user_id=? AND status='approved' LIMIT 1", [$userId])->fetch();
}

function billing_plans($pl) {
    $user = q("SELECT plan, plan_status, plan_valid_until, whatsapp_number FROM users WHERE id=?", [$pl['sub']])->fetch();
    $pending = q("SELECT * FROM subscription_requests WHERE user_id=? AND status='pending' ORDER BY created_at DESC LIMIT 1", [$pl['sub']])->fetch();
    ok([
        'plans' => PLANS, 'current_plan' => $user['plan'], 'plan_status' => $user['plan_status'],
        'plan_valid_until' => $user['plan_valid_until'],
        'pending_request' => $pending ?: null,
        'free_trial_eligible' => !user_ever_had_approved_subscription($pl['sub']),
        'payment_phone' => PAYMENT_PHONE_DISPLAY,
        'merchant_whatsapp' => $user['whatsapp_number'],
        'payment_instructions' => 'Envoyez le montant du plan choisi via Orange Money, Wave ou Djomo au '.PAYMENT_PHONE_DISPLAY.' (MYBOUTIK). Votre plan sera active des verification manuelle du paiement par l\'equipe MYBOUTIK (generalement sous 24h).',
    ]);
}

function billing_subscribe($pl) {
    $b = body();
    $plan = $b['plan'] ?? '';
    if (!isset(PLANS[$plan])) fail('Plan invalide');
    // Starter gratuit le premier mois, mais seulement pour un compte qui
    // n'a jamais eu d'abonnement approuve avant (voir
    // user_ever_had_approved_subscription()) - active immediatement, sans
    // paiement ni validation admin, et sans commission de parrainage
    // (aucun argent reel n'a change de mains). billing_cycle='free_trial'
    // pour que les stats de revenu (subscription_request_amount()) ne le
    // comptent jamais comme un vrai paiement.
    if ($plan === 'starter' && !user_ever_had_approved_subscription($pl['sub'])) {
        $id = uid();
        q("INSERT INTO subscription_requests (id,user_id,plan,status,reviewed_at,billing_cycle) VALUES (?,?,?,'approved',NOW(),'free_trial')", [$id, $pl['sub'], $plan]);
        q("UPDATE users SET plan='starter', plan_status='active', plan_valid_until=NOW() + INTERVAL '30 days' WHERE id=?", [$pl['sub']]);
        ok(null, 'Votre mois gratuit Starter est active immediatement !', 201);
    }
    // Formule annuelle : paie 12x le tarif mensuel mais 13 mois sont
    // accordes a l'approbation (voir admin_subscription_approve()) - "1
    // mois offert". Le prix affiche/mentionne ici reste le prix MENSUEL
    // (PLANS) ; seul le total a envoyer, calcule ici, change avec le cycle.
    $annual = !!($b['annual'] ?? false);
    $cycle = $annual ? 'annual' : 'monthly';
    $existing = q("SELECT id FROM subscription_requests WHERE user_id=? AND plan=? AND status='pending'", [$pl['sub'], $plan])->fetch();
    if ($existing) { ok(null, 'Demande deja en attente de verification'); }
    $amount = PLANS[$plan]['price'] * ($annual ? 12 : 1);
    $amountLabel = number_format($amount, 0, ',', ' ').' FCFA'.($annual ? ' (12 mois, 1 mois offert = 13 mois d\'acces)' : '');
    $id = uid();
    q("INSERT INTO subscription_requests (id,user_id,plan,billing_cycle) VALUES (?,?,?,?)", [$id, $pl['sub'], $plan, $cycle]);
    ok(null, 'Demande enregistree. Envoyez '.$amountLabel.' via Orange Money, Wave ou Djomo au '.PAYMENT_PHONE_DISPLAY.' (MYBOUTIK) - votre plan sera active des verification du paiement par l\'equipe MYBOUTIK (generalement sous 24h).', 201);
}

function billing_affiliate_info($pl) {
    $user = q("SELECT referral_code, referral_clicks FROM users WHERE id=?", [$pl['sub']])->fetch();
    if (!$user['referral_code']) {
        $code = generate_unique_referral_code();
        q("UPDATE users SET referral_code=? WHERE id=?", [$code, $pl['sub']]);
        $user['referral_code'] = $code;
    }
    $referred = q("SELECT email, full_name, plan_status, created_at FROM users WHERE referred_by=? ORDER BY created_at DESC", [$pl['sub']])->fetchAll();
    $payingCount = count(array_filter($referred, fn($r) => $r['plan_status'] === 'active'));

    $commissions = q("SELECT * FROM referral_commissions WHERE referrer_user_id=? ORDER BY created_at DESC", [$pl['sub']])->fetchAll();
    $pending = 0; $available = 0; $paid = 0;
    $cutoff = time() - REFERRAL_VALIDATION_DAYS * 86400;
    foreach ($commissions as $c) {
        $amount = (float)$c['amount'];
        if ($c['status'] === 'paid') { $paid += $amount; }
        elseif ($c['status'] === 'requested') { /* deja compte comme "disponible" au moment de la demande, en cours de versement */ $available += 0; }
        elseif ($c['status'] === 'pending' && strtotime($c['created_at']) <= $cutoff) { $available += $amount; }
        else { $pending += $amount; }
    }
    $payouts = q("SELECT * FROM referral_payouts WHERE user_id=? ORDER BY created_at DESC", [$pl['sub']])->fetchAll();

    ok([
        'referral_code' => $user['referral_code'],
        'clicks' => (int)$user['referral_clicks'],
        'signups' => count($referred),
        'paying_referrals' => $payingCount,
        'total_earned' => $pending + $available + $paid,
        'pending_validation' => $pending,
        'available' => $available,
        'paid' => $paid,
        'min_payout' => REFERRAL_MIN_PAYOUT,
        'validation_days' => REFERRAL_VALIDATION_DAYS,
        'referred_users' => $referred,
        'payouts' => $payouts,
    ]);
}

function billing_affiliate_request_payout($pl) {
    $b = body();
    $method = trim($b['method'] ?? '');
    $phone = trim($b['phone'] ?? '');
    if ($phone === '') fail('Numero Mobile Money requis');
    $cutoff = date('Y-m-d H:i:s', time() - REFERRAL_VALIDATION_DAYS * 86400);
    $available = q("SELECT * FROM referral_commissions WHERE referrer_user_id=? AND status='pending' AND created_at <= ?", [$pl['sub'], $cutoff])->fetchAll();
    $total = array_sum(array_column($available, 'amount'));
    if ($total < REFERRAL_MIN_PAYOUT) {
        fail('Le solde disponible doit atteindre au moins '.REFERRAL_MIN_PAYOUT.' FCFA pour demander un retrait');
    }
    $id = uid();
    q("INSERT INTO referral_payouts (id,user_id,amount,method,phone,status) VALUES (?,?,?,?,?,'requested')",
      [$id, $pl['sub'], $total, $method, $phone]);
    foreach ($available as $c) { q("UPDATE referral_commissions SET status='requested' WHERE id=?", [$c['id']]); }
    ok(null, 'Demande de retrait envoyee. Vous serez paye(e) apres verification.', 201);
}

// ============================================================
// AVIS DES PROPRIETAIRES SUR MYBOUTIK — envoyes depuis le tableau de bord
// marchand (page Profil), lus et traites depuis le panneau admin (bouton
// "Repondre" -> mailto:, voir admin.html).
// ============================================================
function route_feedback($action) {
    // submit_public : ouvert a tout client acheteur, sans compte (identifie
    // par nom/telephone saisis a la volee, comme au checkout) - toutes les
    // autres actions restent reservees aux marchands connectes.
    if ($action === 'submit_public') { feedback_submit_public(); return; }
    $pl = owner_auth();
    switch ($action) {
        case 'submit': feedback_submit($pl); break;
        default: fail('Action inconnue', 404);
    }
}
function feedback_submit($pl) {
    $b = body();
    $rating = (int)($b['rating'] ?? 0);
    $message = trim($b['message'] ?? '');
    if ($rating < 1 || $rating > 5) fail('Note invalide (1 a 5)');
    if ($message === '') fail('Le message ne peut pas etre vide');
    q("INSERT INTO platform_feedback (id,user_id,rating,message) VALUES (?,?,?,?)", [uid(), $pl['sub'], $rating, $message]);
    ok(null, 'Avis envoye. Merci pour votre retour !', 201);
}
function feedback_submit_public() {
    rate_limit_check('feedback_submit_public', 5, 1800);
    $b = body();
    $name = trim($b['customer_name'] ?? '');
    $phone = trim($b['customer_phone'] ?? '');
    $email = trim($b['customer_email'] ?? '');
    $rating = (int)($b['rating'] ?? 0);
    $message = trim($b['message'] ?? '');
    if ($name === '') fail('Votre nom est requis');
    if ($phone === '') fail('Votre telephone est requis');
    if ($rating < 1 || $rating > 5) fail('Note invalide (1 a 5)');
    if ($message === '') fail('Le message ne peut pas etre vide');
    q("INSERT INTO platform_feedback (id,customer_name,customer_phone,customer_email,rating,message) VALUES (?,?,?,?,?,?)",
      [uid(), $name, $phone, $email, $rating, $message]);
    ok(null, 'Avis envoye. Merci pour votre retour !', 201);
}

// ============================================================
// ADMIN — panneau reserve a l'operateur de MYBOUTIK (mot de passe distinct
// des comptes marchands), pour valider les demandes d'abonnement. Pas de
// JWT ici : le mot de passe est verifie a chaque appel, comme le panel
// admin de ROM_MONEY.
// ============================================================
function route_admin($action) {
    if (!ADMIN_PASSWORD) fail('Panneau admin non configure (variable ADMIN_PASSWORD absente)', 503);
    $password = bg('password', '');
    // Le compteur ne s'incremente que sur un MAUVAIS mot de passe - le
    // panneau admin declenche 7 appels par chargement de page (un par
    // carte), donc compter aussi les appels avec le bon mot de passe
    // epuisait le quota au bout de 4-5 rechargements en 5 minutes, meme
    // pour l'operateur legitime. La protection anti-bruteforce reste
    // intacte : un mot de passe errone continue de compter.
    if (!hash_equals(ADMIN_PASSWORD, (string)$password)) {
        rate_limit_check('admin_auth', 30, 300);
        fail('Mot de passe incorrect', 403);
    }
    switch ($action) {
        case 'subscription_requests': admin_subscription_requests(); break;
        case 'subscription_approve':  admin_subscription_approve(); break;
        case 'subscription_reject':   admin_subscription_reject(); break;
        case 'payouts_pending':       admin_payouts_pending(); break;
        case 'payout_mark_paid':      admin_payout_mark_paid(); break;
        case 'clients_list':          admin_clients_list(); break;
        case 'subscription_history':  admin_subscription_history(); break;
        case 'revenue_by_month':      admin_revenue_by_month(); break;
        case 'feedback_list':         admin_feedback_list(); break;
        case 'feedback_mark_replied': admin_feedback_mark_replied(); break;
        case 'boutiques_list':        admin_boutiques_list(); break;
        case 'boutique_set_status':   admin_boutique_set_status(); break;
        case 'boutique_warn':         admin_boutique_warn(); break;
        case 'actions_log':           admin_actions_log(); break;
        case 'disputes_list':         admin_disputes_list(); break;
        case 'low_reviews_list':      admin_low_reviews_list(); break;
        case 'top_boutiques':         admin_top_boutiques(); break;
        case 'period_stats':          admin_period_stats(); break;
        case 'rates_list':            admin_rates_list(); break;
        case 'rates_save':            admin_rates_save(); break;
        case 'announcements_list':    admin_announcements_list(); break;
        case 'announcement_create':   admin_announcement_create(); break;
        case 'announcement_audience_count': admin_announcement_audience_count(); break;
        case 'announcement_set_active': admin_announcement_set_active(); break;
        case 'announcement_delete':   admin_announcement_delete(); break;
        case 'seed_demo_data':        admin_seed_demo_data(); break;
        case 'seed_demo_currency':    admin_seed_demo_currency(); break;
        case 'delete_demo_data':      admin_delete_demo_data(); break;
        default: fail('Action inconnue', 404);
    }
}

function admin_subscription_requests() {
    ok(q("SELECT sr.*, u.email FROM subscription_requests sr JOIN users u ON u.id=sr.user_id
          WHERE sr.status='pending' ORDER BY sr.created_at ASC")->fetchAll());
}

// Vue d'ensemble de tous les clients (proprietaires de compte) - plan,
// statut d'acces, nombre de boutiques, revenu genere a vie (base sur les
// demandes approuvees x prix du plan au moment de l'approbation - PLANS
// peut changer de prix ensuite, mais l'historique reste calcule sur le prix
// ACTUEL du plan concerne, faute de stocker un prix fige par demande).
function admin_clients_list() {
    $users = q("SELECT id, email, full_name, plan, plan_status, plan_valid_until, created_at FROM users ORDER BY created_at DESC")->fetchAll();
    foreach ($users as &$u) {
        $owned = q("SELECT name FROM boutiques WHERE owner_user_id=? ORDER BY created_at", [$u['id']])->fetchAll();
        $u['boutique_count'] = count($owned);
        $u['boutique_names'] = array_column($owned, 'name');
        $member = q("SELECT b.name FROM boutique_members bm JOIN boutiques b ON b.id=bm.boutique_id
                     WHERE bm.user_id=? AND bm.status='active'", [$u['id']])->fetchAll();
        $u['member_boutique_count'] = count($member);
        $u['member_boutique_names'] = array_column($member, 'name');
        $approved = q("SELECT plan, billing_cycle FROM subscription_requests WHERE user_id=? AND status='approved'", [$u['id']])->fetchAll();
        $u['payments_count'] = count($approved);
        $u['total_paid'] = array_sum(array_map('subscription_request_amount', $approved));
    }
    ok($users);
}

// Historique complet (toutes demandes, tous statuts) - contrairement a
// admin_subscription_requests() qui ne montre que les demandes en attente
// d'action, celle-ci sert a retracer l'activite de paiement passee.
function admin_subscription_history() {
    ok(q("SELECT sr.*, u.email FROM subscription_requests sr JOIN users u ON u.id=sr.user_id
          ORDER BY sr.created_at DESC LIMIT 300")->fetchAll());
}

// Revenu regroupe par mois (annee-mois), base sur la date d'APPROBATION
// (reviewed_at) - c'est le moment ou l'argent a reellement ete verifie
// recu, pas la date de la demande initiale. Prix pris au tarif ACTUEL du
// plan (PLANS), comme admin_clients_list() - aucun prix historique fige par
// demande n'est stocke.
function admin_revenue_by_month() {
    $rows = q("SELECT plan, billing_cycle, COALESCE(reviewed_at, created_at) AS paid_at FROM subscription_requests WHERE status='approved'")->fetchAll();
    $byMonth = [];
    foreach ($rows as $r) {
        $key = date('Y-m', strtotime($r['paid_at']));
        $byMonth[$key] = ($byMonth[$key] ?? 0) + subscription_request_amount($r);
    }
    ksort($byMonth);
    $result = [];
    foreach ($byMonth as $month => $amount) $result[] = ['month' => $month, 'revenue' => $amount];
    ok($result);
}

function admin_feedback_list() {
    // LEFT JOIN : un avis client anonyme (user_id NULL) n'a pas de compte
    // marchand associe - on retombe alors sur ses customer_* saisis a la volee.
    ok(q("SELECT pf.*, COALESCE(u.full_name, pf.customer_name) AS full_name,
                 COALESCE(u.email, pf.customer_email) AS email,
                 pf.customer_phone, (pf.user_id IS NULL) AS is_customer
          FROM platform_feedback pf
          LEFT JOIN users u ON u.id = pf.user_id
          ORDER BY pf.replied_at IS NOT NULL ASC, pf.created_at DESC LIMIT 300")->fetchAll());
}
function admin_feedback_mark_replied() {
    $b = body();
    $id = $b['id'] ?? '';
    if (!$id) fail('Introuvable', 404);
    q("UPDATE platform_feedback SET replied_at=NOW() WHERE id=?", [$id]);
    ok(null, 'Marque comme repondu');
}

// Vue d'ensemble de toutes les boutiques de la plateforme (pas seulement
// celles d'un marchand connecte) - permet a l'operateur de retrouver une
// boutique signalee et de la suspendre (voir admin_boutique_set_status()).
function admin_boutiques_list() {
    // open_disputes_count/low_reviews_count : signal de qualite a l'echelle
    // de la plateforme (voir admin_disputes_list()/admin_low_reviews_list()
    // pour le detail) - visible ici en un coup d'oeil pour reperer une
    // boutique a surveiller sans avoir a ouvrir les deux listes dediees.
    // total_orders_count/refused_count : taux de refus/annulation, un
    // signal different (fiabilite/stock/possible arnaque) que le frontend
    // n'affiche que si l'echantillon est assez grand (evite d'accuser une
    // boutique sur 1 seule commande refusee).
    // last_order_at : detection d'inactivite (aucune vente recente) cote
    // frontend - ce n'est pas un signal de qualite, plutot une opportunite
    // de relance, mais vit dans la meme vue d'ensemble.
    // warning_count/last_warned_at : etape d'avertissement formel (voir
    // admin_boutique_warn()) avant d'en arriver a la suspension.
    ok(q("SELECT b.id, b.name, b.slug, b.status, b.public_listed, b.category, b.city, b.created_at,
                 b.warning_count, b.last_warned_at,
                 u.email AS owner_email, u.full_name AS owner_name,
                 (SELECT COUNT(*) FROM orders o WHERE o.boutique_id=b.id AND o.dispute_status='open') AS open_disputes_count,
                 (SELECT COUNT(*) FROM product_reviews r WHERE r.boutique_id=b.id AND r.rating<=2) AS low_reviews_count,
                 (SELECT COUNT(*) FROM orders o WHERE o.boutique_id=b.id) AS total_orders_count,
                 (SELECT COUNT(*) FROM orders o WHERE o.boutique_id=b.id AND o.status IN ('refused','cancelled')) AS refused_count,
                 (SELECT MAX(created_at) FROM orders o WHERE o.boutique_id=b.id) AS last_order_at
          FROM boutiques b JOIN users u ON u.id = b.owner_user_id
          ORDER BY b.created_at DESC")->fetchAll());
}
// Avertissement formel avant une eventuelle suspension - trace conservee
// (warning_count/last_warned_at sur la boutique + entree dans activity_log,
// donc visible aussi par le marchand dans son propre journal d'activite,
// jamais une sanction cachee) et email envoye directement au marchand.
function admin_boutique_warn() {
    $b = body();
    $id = $b['id'] ?? '';
    $message = trim($b['message'] ?? '');
    if ($message === '') fail('Ecrivez un message d\'avertissement');
    $row = q("SELECT b.id, b.name, u.email AS owner_email FROM boutiques b JOIN users u ON u.id=b.owner_user_id WHERE b.id=?", [$id])->fetch();
    if (!$row) fail('Boutique introuvable', 404);
    q("UPDATE boutiques SET warning_count = warning_count + 1, last_warned_at = NOW() WHERE id=?", [$id]);
    admin_log($id, 'Avertissement envoye par l\'administration : '.$message);
    send_email($row['owner_email'], 'Avertissement concernant votre boutique '.$row['name'].' - MYBOUTIK',
        "Bonjour,\n\nL'equipe MYBOUTIK vous adresse un avertissement concernant votre boutique ".$row['name'].".\n\n".$message.
        "\n\nMerci de corriger la situation rapidement afin d'eviter une suspension de votre boutique.\n\nL'equipe MYBOUTIK");
    ok(null, 'Avertissement envoye');
}
// Trace des actions administratives (suspension/reactivation/avertissement)
// dans activity_log, marquees par un actor_email dedie pour pouvoir les
// distinguer des actions du marchand lui-meme (voir admin_actions_log()) -
// pas une nouvelle table, juste une convention sur un champ existant.
function admin_log($boutiqueId, $message) {
    q("INSERT INTO activity_log (boutique_id, message, actor_email) VALUES (?,?,?)", [$boutiqueId, $message, 'Administration MYBOUTIK']);
}
// Journal des actions admin (toutes boutiques confondues) - permet de vous
// souvenir si une boutique a deja ete avertie/suspendue recemment sans
// avoir a rouvrir chaque boutique individuellement.
function admin_actions_log() {
    ok(q("SELECT a.id, a.boutique_id, a.message, a.created_at, b.name AS boutique_name
          FROM activity_log a JOIN boutiques b ON b.id = a.boutique_id
          WHERE a.actor_email = 'Administration MYBOUTIK'
          ORDER BY a.created_at DESC LIMIT 100")->fetchAll());
}
// Vue transversale de TOUTES les reclamations clients (toutes boutiques
// confondues) pour que l'admin puisse surveiller la qualite de la
// plateforme sans se connecter au tableau de bord de chaque marchand -
// meme donnees que orders_respond_dispute()/shop_submit_dispute(), juste
// vues depuis l'admin, en lecture seule (la reponse reste du ressort du
// marchand ; l'admin peut seulement suspendre la boutique si besoin).
function admin_disputes_list() {
    // Les reclamations "En attente" remontent avant les "Repondues" (peu
    // importe leur date) pour que ce qui a encore besoin d'attention ne se
    // noie pas au milieu de dossiers deja clos - a l'interieur de chaque
    // groupe, la plus recente en premier.
    ok(q("SELECT o.id, o.ref, o.customer_name, o.dispute_status, o.dispute_message, o.dispute_response,
                 o.dispute_created_at, o.dispute_resolved_at, b.id AS boutique_id, b.name AS boutique_name, b.slug AS boutique_slug
          FROM orders o JOIN boutiques b ON b.id = o.boutique_id
          WHERE o.dispute_status IS NOT NULL
          ORDER BY (o.dispute_status = 'open') DESC, o.dispute_created_at DESC LIMIT 200")->fetchAll());
}
// Top boutiques - meme jeu de donnees pour les 3 classements (revenu,
// articles vendus, nombre de commandes), seul l'ORDER BY change (colonne
// choisie dans une liste blanche, jamais le parametre brut, pour eviter
// toute injection). items_sold vient d'une sous-requete pre-agregee a part
// plutot que d'un JOIN direct vers order_items : joindre puis SUM(o.total)/
// COUNT(o.id) sur la table jointe aurait multiplie ces montants par le
// nombre de lignes d'articles de chaque commande (comptage en double).
function admin_top_boutiques() {
    $b = body();
    $by = $b['by'] ?? 'revenue';
    $orderCol = in_array($by, ['items_sold', 'orders_count'], true) ? $by : 'revenue';
    // Filtre de periode optionnel (barre de periode du panneau admin) : les
    // deux sous-requetes ont chacune leurs propres placeholders, dans
    // l'ordre ou elles apparaissent dans le SQL.
    [$from, $to] = admin_period_bounds($b);
    $ordParams = []; $ordDate = admin_period_sql('created_at', $from, $to, $ordParams);
    $itemParams = []; $itemDate = admin_period_sql('o.created_at', $from, $to, $itemParams);
    $rows = q("SELECT b.id, b.name, b.slug, b.city, b.country, b.currency,
                 COALESCE(ord.orders_count,0) AS orders_count,
                 COALESCE(ord.revenue,0) AS revenue,
                 COALESCE(items.items_sold,0) AS items_sold
          FROM boutiques b
          JOIN (
            SELECT boutique_id, COUNT(*) AS orders_count, SUM(total) AS revenue
            FROM orders WHERE status IN ".ENCAISSE_STATUSES.$ordDate."
            GROUP BY boutique_id
          ) ord ON ord.boutique_id = b.id
          LEFT JOIN (
            SELECT o.boutique_id, SUM(oi.qty) AS items_sold
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            WHERE o.status IN ".ENCAISSE_STATUSES.$itemDate."
            GROUP BY o.boutique_id
          ) items ON items.boutique_id = b.id
          ORDER BY $orderCol DESC".($orderCol === 'revenue' ? '' : ' LIMIT 10'), array_merge($ordParams, $itemParams))->fetchAll();
    // Le CA de boutiques en devises differentes ne se compare qu'une fois
    // converti en FCFA : classement par revenue_xof (null = taux manquant, en
    // fin de liste). Les classements par articles/commandes n'en dependent pas.
    $rates = currency_rates();
    foreach ($rows as &$r) {
        $rate = $rates[$r['currency'] ?: 'XOF'] ?? null;
        $r['revenue_xof'] = $rate === null ? null : round((float)$r['revenue'] * $rate);
    }
    unset($r);
    if ($orderCol === 'revenue') {
        usort($rows, function($x, $y) { return ($y['revenue_xof'] ?? -1) <=> ($x['revenue_xof'] ?? -1); });
        $rows = array_slice($rows, 0, 10);
    }
    ok($rows);
}
// ============================================================
// ANNONCES (admin -> marchands) : bandeau en haut du tableau de bord.
// Destinataires = des COMPTES marchands (users), choisis par l'admin :
// tous, abonnement qui expire dans 5 jours ou moins (meme seuil que le
// bandeau d'avertissement de la page Abonnement), abonnement expire, un plan
// precis, ou les proprietaires d'au moins une boutique d'un pays. Le compte
// de demonstration n'est jamais compte ni cible.
// ============================================================
function announcement_audience_where($aud, $val, &$params) {
    $where = "u.status='active' AND u.email <> ?";
    $params[] = DEMO_SEED_EMAIL;
    switch ($aud) {
        case 'expiring': return $where." AND u.plan_valid_until >= NOW() AND u.plan_valid_until < NOW() + INTERVAL '5 days'";
        case 'expired':  return $where." AND u.plan_valid_until < NOW()";
        case 'plan':     $params[] = $val; return $where." AND u.plan=?";
        case 'country':  $params[] = $val; return $where." AND EXISTS (SELECT 1 FROM boutiques b WHERE b.owner_user_id=u.id AND b.country=?)";
        default:         return $where;
    }
}
function announcement_recipients($aud, $val) {
    $params = [];
    $where = announcement_audience_where($aud, $val, $params);
    return (int)q("SELECT COUNT(*) c FROM users u WHERE $where", $params)->fetch()['c'];
}
function announcement_valid_audience($aud, $val) {
    if (!in_array($aud, ['all', 'expiring', 'expired', 'plan', 'country'], true)) return false;
    if ($aud === 'plan') return is_string($val) && isset(PLANS[$val]);
    if ($aud === 'country') return is_string($val) && $val !== '' && strlen($val) <= 80;
    return true;
}
// Cote marchand : uniquement l'utilisateur connecte (jamais un autre compte).
function route_announcements($action) {
    $pl = owner_auth();
    switch ($action) {
        case 'active':  announcements_active($pl); break;
        case 'dismiss': announcements_dismiss($pl); break;
        default: fail('Action inconnue', 404);
    }
}
// Annonces en ligne (actives, dans leurs dates), destinees a CE marchand et
// qu'il n'a pas fermees - 3 au maximum, la plus recente en premier. Une base
// pas encore mise a jour (/install) donne simplement "aucune annonce".
function announcements_active($pl) {
    try {
        $rows = q("SELECT a.id, a.title, a.message, a.level, a.audience, a.audience_value FROM announcements a
                   WHERE a.active=1 AND a.starts_at <= NOW() AND (a.ends_at IS NULL OR a.ends_at > NOW())
                     AND NOT EXISTS (SELECT 1 FROM announcement_dismissals d WHERE d.announcement_id=a.id AND d.user_id=?)
                   ORDER BY a.created_at DESC LIMIT 20", [$pl['sub']])->fetchAll();
        $out = [];
        foreach ($rows as $a) {
            $params = [];
            $where = announcement_audience_where($a['audience'], $a['audience_value'], $params);
            if (!q("SELECT 1 FROM users u WHERE u.id=? AND $where", array_merge([$pl['sub']], $params))->fetch()) continue;
            $out[] = ['id' => $a['id'], 'title' => $a['title'], 'message' => $a['message'], 'level' => $a['level']];
            if (count($out) >= 3) break;
        }
        ok($out);
    } catch (PDOException $e) {
        ok([]);
    }
}
function announcements_dismiss($pl) {
    $b = body();
    $id = $b['id'] ?? '';
    try {
        if (!q("SELECT 1 FROM announcements WHERE id=?", [$id])->fetch()) fail('Annonce introuvable', 404);
        q("INSERT INTO announcement_dismissals (announcement_id, user_id) VALUES (?,?) ON CONFLICT DO NOTHING", [$id, $pl['sub']]);
    } catch (PDOException $e) {
        error_log('[MYBOUTIK] announcements: '.$e->getMessage());
        fail('Table des annonces absente : relancez /install puis reessayez', 500);
    }
    ok(null, 'OK');
}
// Cote admin.
function admin_announcement_audience_count() {
    $b = body();
    $aud = $b['audience'] ?? 'all';
    $val = $b['audience_value'] ?? null;
    if (!announcement_valid_audience($aud, $val)) ok(['count' => 0]);
    ok(['count' => announcement_recipients($aud, $val)]);
}
function admin_announcement_create() {
    $b = body();
    $title = trim((string)($b['title'] ?? ''));
    $message = trim((string)($b['message'] ?? ''));
    $len = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
    if ($message === '') fail('Ecrivez le message de l\'annonce');
    if ($len($message) > 1000 || $len($title) > 120) fail('Message trop long (1000 caracteres maximum)');
    $level = in_array($b['level'] ?? '', ['info', 'warning', 'success'], true) ? $b['level'] : 'info';
    $aud = $b['audience'] ?? 'all';
    $val = $b['audience_value'] ?? null;
    if (!announcement_valid_audience($aud, $val)) fail('Destinataires invalides');
    $endsOn = trim((string)($b['ends_on'] ?? ''));
    $endsAt = null;
    if ($endsOn !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn)) fail('Date de fin invalide');
        if ($endsOn < date('Y-m-d')) fail('La date de fin doit etre dans le futur');
        $endsAt = $endsOn.' 23:59:59';
    }
    $id = uid();
    try {
        q("INSERT INTO announcements (id,title,message,level,audience,audience_value,ends_at) VALUES (?,?,?,?,?,?,?)",
          [$id, $title !== '' ? $title : null, $message, $level, $aud, in_array($aud, ['plan', 'country'], true) ? $val : null, $endsAt]);
    } catch (PDOException $e) {
        error_log('[MYBOUTIK] announcements: '.$e->getMessage());
        fail('Table des annonces absente : relancez /install puis reessayez', 500);
    }
    ok(['id' => $id, 'recipients' => announcement_recipients($aud, $val)], 'Annonce publiee');
}
function admin_announcements_list() {
    try {
        $rows = q("SELECT a.*, (SELECT COUNT(*) FROM announcement_dismissals d WHERE d.announcement_id=a.id) AS dismissed_count
                   FROM announcements a ORDER BY a.created_at DESC LIMIT 100")->fetchAll();
    } catch (PDOException $e) {
        error_log('[MYBOUTIK] announcements: '.$e->getMessage());
        fail('Table des annonces absente : relancez /install puis reessayez', 500);
    }
    foreach ($rows as &$r) {
        $r['recipients'] = announcement_recipients($r['audience'], $r['audience_value']);
        $ended = $r['ends_at'] && strtotime($r['ends_at']) < time();
        $r['status'] = !(int)$r['active'] ? 'withdrawn' : ($ended ? 'ended' : 'live');
    }
    unset($r);
    $countries = q("SELECT DISTINCT country FROM boutiques WHERE country IS NOT NULL AND country <> '' ORDER BY country")->fetchAll(PDO::FETCH_COLUMN);
    ok(['items' => $rows, 'countries' => $countries]);
}
function admin_announcement_set_active() {
    $b = body();
    $id = $b['id'] ?? '';
    if (!q("SELECT 1 FROM announcements WHERE id=?", [$id])->fetch()) fail('Annonce introuvable', 404);
    q("UPDATE announcements SET active=? WHERE id=?", [!empty($b['active']) ? 1 : 0, $id]);
    ok(null, 'Annonce mise a jour');
}
function admin_announcement_delete() {
    $b = body();
    $id = $b['id'] ?? '';
    q("DELETE FROM announcement_dismissals WHERE announcement_id=?", [$id]);
    q("DELETE FROM announcements WHERE id=?", [$id]);
    ok(null, 'Annonce supprimee');
}
// Taux de change : une ligne par devise utilisee par au moins une boutique
// (hors XOF, qui vaut 1). source : 'fixed' (parite officielle, non
// modifiable), 'custom' (saisi par l'admin), 'live' (taux du jour
// automatique) ou 'missing' (aucun taux connu, a renseigner).
function admin_rates_list() {
    $rates = currency_rates();
    $custom = currency_rates_custom();
    $codes = q("SELECT DISTINCT COALESCE(currency,'XOF') AS c FROM boutiques ORDER BY c")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($codes as $c) {
        if ($c === 'XOF') continue;
        // 'live' = taux du jour automatique (modifiable : la valeur saisie le remplace)
        $out[] = ['currency' => $c, 'rate' => isset($rates[$c]) ? round($rates[$c], 6) : null,
                  'source' => isset(FIXED_CFA_RATES[$c]) ? 'fixed' : (isset($custom[$c]) ? 'custom' : (isset($rates[$c]) ? 'live' : 'missing'))];
    }
    ok($out);
}
// Enregistre les taux saisis ({rates:{USD: 600.5, ...}}) : une valeur vide
// supprime le taux de cette devise ; les parites fixes ne sont jamais modifiees.
function admin_rates_save() {
    $b = body();
    $in = is_array($b['rates'] ?? null) ? $b['rates'] : [];
    try {
        foreach ($in as $code => $val) {
            if (!valid_currency($code) || isset(FIXED_CFA_RATES[$code])) continue;
            if ($val === '' || $val === null) { q("DELETE FROM currency_rates WHERE currency=?", [$code]); continue; }
            if (!is_numeric($val) || (float)$val <= 0) fail('Taux de change invalide');
            q("INSERT INTO currency_rates (currency, rate_to_xof) VALUES (?,?)
               ON CONFLICT (currency) DO UPDATE SET rate_to_xof=EXCLUDED.rate_to_xof, updated_at=NOW()", [$code, (float)$val]);
        }
    } catch (PDOException $e) {
        error_log('[MYBOUTIK] currency_rates: '.$e->getMessage());
        fail('Table des taux absente : relancez /install puis reessayez', 500);
    }
    ok(null, 'Taux enregistres');
}
// Bornes de periode envoyees par la barre de periode du panneau admin
// (from/to = dates AAAA-MM-JJ, l'une ou l'autre ou les deux, ou aucune =
// "Tous"). Toute valeur qui n'a pas exactement ce format est ignoree.
function admin_period_bounds($b) {
    $ok = function($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1; };
    return [$ok($b['from'] ?? null) ? $b['from'] : null, $ok($b['to'] ?? null) ? $b['to'] : null];
}
// Fragment SQL " AND col >= ... AND col < ..." (borne haute exclusive =
// lendemain de "to", pour inclure toute la journee "to"), et ajoute les
// valeurs correspondantes a $params. $col vient toujours du code, jamais de
// l'utilisateur.
function admin_period_sql($col, $from, $to, &$params) {
    $sql = '';
    if ($from) { $sql .= " AND $col >= ?::date"; $params[] = $from; }
    if ($to)   { $sql .= " AND $col < (?::date + INTERVAL '1 day')"; $params[] = $to; }
    return $sql;
}
// Chiffres globaux de la periode choisie (Vue d'ensemble) : "Volume" = ce
// que TOUTES les boutiques ont encaisse sur la periode, "Gains" = ce que
// MYBOUTIK a encaisse en abonnements (meme calcul de prix que
// admin_revenue_by_month()), plus quelques compteurs de croissance/qualite.
function admin_period_stats() {
    [$from, $to] = admin_period_bounds(body());
    $p = []; $d = admin_period_sql('created_at', $from, $to, $p);
    $vol = q("SELECT COUNT(*) c FROM orders WHERE status IN ".ENCAISSE_STATUSES.$d, $p)->fetch();
    // Une somme melangeant XOF, EUR... n'aurait aucun sens : volume par devise.
    $pv = []; $dv = admin_period_sql('o.created_at', $from, $to, $pv);
    $volByCurrency = q("SELECT COALESCE(b.currency,'XOF') AS currency, COALESCE(SUM(o.total),0) AS amount FROM orders o JOIN boutiques b ON b.id=o.boutique_id
                        WHERE o.status IN ".ENCAISSE_STATUSES.$dv." GROUP BY COALESCE(b.currency,'XOF') ORDER BY amount DESC", $pv)->fetchAll();
    // Total converti en FCFA avec les taux connus ; une devise sans taux est
    // listee a part (volume_missing_rates) et exclue du total.
    $rates = currency_rates(); $totalXof = 0.0; $missingRates = [];
    foreach ($volByCurrency as $v) {
        if (isset($rates[$v['currency']])) $totalXof += (float)$v['amount'] * $rates[$v['currency']];
        else $missingRates[] = $v['currency'];
    }
    $p2 = []; $d2 = admin_period_sql('COALESCE(reviewed_at, created_at)', $from, $to, $p2);
    $subs = q("SELECT plan, billing_cycle FROM subscription_requests WHERE status='approved'".$d2, $p2)->fetchAll();
    $gains = 0; foreach ($subs as $r) $gains += subscription_request_amount($r);
    $p3 = []; $d3 = admin_period_sql('created_at', $from, $to, $p3);
    $newUsers = (int)q("SELECT COUNT(*) c FROM users WHERE TRUE".$d3, $p3)->fetch()['c'];
    $newBoutiques = (int)q("SELECT COUNT(*) c FROM boutiques WHERE TRUE".$d3, $p3)->fetch()['c'];
    $lowReviews = (int)q("SELECT COUNT(*) c FROM product_reviews WHERE rating<=2".$d3, $p3)->fetch()['c'];
    $p4 = []; $d4 = admin_period_sql('dispute_created_at', $from, $to, $p4);
    $disputes = (int)q("SELECT COUNT(*) c FROM orders WHERE dispute_status IS NOT NULL".$d4, $p4)->fetch()['c'];
    ok([
        'volume_orders' => (int)$vol['c'], 'volume_by_currency' => $volByCurrency,
        'volume_total_xof' => round($totalXof), 'volume_missing_rates' => $missingRates,
        'gains' => $gains, 'approved_count' => count($subs),
        'new_users' => $newUsers, 'new_boutiques' => $newBoutiques,
        'disputes' => $disputes, 'low_reviews' => $lowReviews,
    ]);
}
// Avis clients note <= 2 etoiles, toutes boutiques confondues - autre
// signal de qualite/produit non conforme ou dangereux a surveiller sans
// dependre du marchand pour les remonter lui-meme (un marchand de mauvaise
// foi pourrait les laisser en attente de moderation indefiniment - voir
// product_reviews.status - donc on ne filtre pas sur le statut ici).
function admin_low_reviews_list() {
    ok(q("SELECT r.id, r.customer_name, r.rating, r.comment, r.status, r.created_at,
                 p.name AS product_name, b.id AS boutique_id, b.name AS boutique_name, b.slug AS boutique_slug
          FROM product_reviews r JOIN boutiques b ON b.id = r.boutique_id
          LEFT JOIN products p ON p.id = r.product_id
          WHERE r.rating <= 2
          ORDER BY r.created_at DESC LIMIT 200")->fetchAll());
}
// Suspendre = status different de 'active' : toutes les routes publiques
// (vitrine, apercus OG, annuaire, alertes de stock) filtrent deja sur
// status='active', donc ca coupe immediatement la boutique du public sans
// toucher aux donnees ni empecher le marchand de se connecter a son
// tableau de bord pour voir ce qui se passe.
function admin_boutique_set_status() {
    $b = body();
    $id = $b['id'] ?? '';
    $status = $b['status'] ?? '';
    if (!in_array($status, ['active', 'suspended'], true)) fail('Statut invalide');
    $row = q("SELECT id FROM boutiques WHERE id=?", [$id])->fetch();
    if (!$row) fail('Boutique introuvable', 404);
    q("UPDATE boutiques SET status=? WHERE id=?", [$status, $id]);
    admin_log($id, $status === 'suspended' ? 'Boutique suspendue par l\'administration' : 'Boutique reactivee par l\'administration');
    ok(null, $status === 'suspended' ? 'Boutique suspendue' : 'Boutique reactivee');
}

// Donnees de demonstration (pub/captures d'ecran) - toutes regroupees sous
// UN SEUL compte marchand dedie (DEMO_SEED_EMAIL), jamais melangees aux
// vrais comptes, pour qu'admin_delete_demo_data() puisse tout retirer en
// un clic en ne touchant qu'a ce compte. Insertion directe en SQL (pas via
// boutiques_create()) pour ignorer la limite de boutiques par plan - ce
// compte n'est jamais cense se connecter normalement.
function demo_seed_catalog() {
    return [
        ['name'=>'Chez Awa Mode', 'category'=>'mode', 'city'=>'Abidjan', 'country'=>'Côte d\'Ivoire', 'photo'=>'clothing',
         'desc'=>'Mode féminine et masculine tendance, livrée où que vous soyez.', 'products'=>[
            ['Robe wax bleue',15000],['Chemise homme blanche',8000],['Jean slim noir',12000],['Boubou traditionnel',25000],['Ceinture cuir',5000]]],
        ['name'=>'TechPlus Abidjan', 'category'=>'electronique', 'city'=>'Abidjan', 'country'=>'Côte d\'Ivoire', 'photo'=>'electronics,gadget',
         'desc'=>'Accessoires et gadgets tech au meilleur prix.', 'products'=>[
            ['Casque Bluetooth',12000],['Chargeur rapide type-C',3500],['Powerbank 10000mAh',15000],['Écouteurs sans fil',8000],['Câble USB-C',2000]]],
        ['name'=>'Ivoire Beauté', 'category'=>'beaute', 'city'=>'Abidjan', 'country'=>'Côte d\'Ivoire', 'photo'=>'makeup',
         'desc'=>'Cosmétiques et soins naturels 100% africains.', 'products'=>[
            ['Huile de karité',3000],['Savon noir africain',1500],['Crème éclaircissante naturelle',5000],['Parfum femme',10000],['Kit soin visage',7000]]],
        ['name'=>'Sénégal Saveurs', 'category'=>'alimentation', 'city'=>'Dakar', 'country'=>'Sénégal', 'photo'=>'meal',
         'desc'=>'Plats et épices sénégalais faits maison.', 'products'=>[
            ['Thiéboudienne prêt à cuire',5000],['Épices Yassa',2000],['Jus de bissap',1000],['Arachides grillées',1500],['Miel local',3500]]],
        ['name'=>'La Maison du Wax', 'category'=>'mode', 'city'=>'Bouaké', 'country'=>'Côte d\'Ivoire', 'photo'=>'clothing',
         'desc'=>'Le wax authentique, en tissu ou déjà cousu.', 'products'=>[
            ['Tissu wax 6 yards',18000],['Robe pagne',20000],['Foulard assorti',3000],['Sac à main pagne',8000],['Turban wax',2500]]],
        ['name'=>'Bijoux d\'Afrique', 'category'=>'mode', 'city'=>'Cotonou', 'country'=>'Bénin', 'photo'=>'jewelry',
         'desc'=>'Bijoux artisanaux inspirés des traditions africaines.', 'products'=>[
            ['Collier perles',4000],['Bracelet assorti',2000],['Bague ethnique',3500],['Boucles d\'oreilles',2500],['Coffret bijoux',12000]]],
        ['name'=>'Sport Elite CI', 'category'=>'autre', 'city'=>'Abidjan', 'country'=>'Côte d\'Ivoire', 'photo'=>'sport',
         'desc'=>'Tout l\'équipement pour vos entraînements et matchs.', 'products'=>[
            ['Maillot de foot',9000],['Ballon de football',7000],['Chaussures de sport',20000],['Short d\'entraînement',5000],['Gourde sport',2500]]],
        ['name'=>'Cosmétiques Nature', 'category'=>'beaute', 'city'=>'Lomé', 'country'=>'Togo', 'photo'=>'makeup',
         'desc'=>'Produits de beauté naturels et bio.', 'products'=>[
            ['Gel douche naturel',2500],['Shampoing karité',3000],['Masque argile',2000],['Baume à lèvres',1000],['Gommage corps',4000]]],
        ['name'=>'Deco & Style', 'category'=>'maison', 'city'=>'Abidjan', 'country'=>'Côte d\'Ivoire', 'photo'=>'furniture,home',
         'desc'=>'Décorez votre intérieur avec style et originalité.', 'products'=>[
            ['Coussin décoratif',4000],['Vase artisanal',8000],['Tapis salon',25000],['Lampe design',15000],['Cadre photo',3000]]],
        ['name'=>'Mobile Store 225', 'category'=>'electronique', 'city'=>'Abidjan', 'country'=>'Côte d\'Ivoire', 'photo'=>'electronics,gadget',
         'desc'=>'Accessoires téléphone et petits gadgets électroniques.', 'products'=>[
            ['Coque téléphone',2000],['Film protecteur écran',1500],['Support téléphone voiture',3000],['Enceinte portable',12000],['Adaptateur SIM',500]]],
        ['name'=>'Chaussures Prestige', 'category'=>'mode', 'city'=>'Yamoussoukro', 'country'=>'Côte d\'Ivoire', 'photo'=>'shoes',
         'desc'=>'Chaussures élégantes pour toute la famille.', 'products'=>[
            ['Sandales cuir homme',10000],['Escarpins femme',15000],['Baskets tendance',18000],['Mocassins',12000],['Sandales enfant',6000]]],
        ['name'=>'Miel & Épices', 'category'=>'alimentation', 'city'=>'Ouagadougou', 'country'=>'Burkina Faso', 'photo'=>'meal',
         'desc'=>'Miel pur et épices locales, directement du producteur.', 'products'=>[
            ['Miel pur',3000],['Poivre de Guinée',1500],['Gingembre séché',1000],['Attiéké prêt',2000],['Piment en poudre',800]]],
        ['name'=>'Baby Kids Shop', 'category'=>'autre', 'city'=>'Abidjan', 'country'=>'Côte d\'Ivoire', 'photo'=>'baby',
         'desc'=>'Tout pour bébé et les tout-petits, qualité et douceur.', 'products'=>[
            ['Body bébé coton',3000],['Couches lavables',8000],['Jouet éducatif',6000],['Biberon anti-colique',4000],['Peluche douce',5000]]],
        ['name'=>'Électro Ménager Plus', 'category'=>'electronique', 'city'=>'Dakar', 'country'=>'Sénégal', 'photo'=>'electronics,gadget',
         'desc'=>'Électroménager fiable pour la maison au quotidien.', 'products'=>[
            ['Mixeur électrique',15000],['Bouilloire',10000],['Ventilateur de table',12000],['Fer à repasser',9000],['Lampe torche rechargeable',5000]]],
        ['name'=>'Fashion Corner', 'category'=>'mode', 'city'=>'Abidjan', 'country'=>'Côte d\'Ivoire', 'photo'=>'clothing',
         'desc'=>'Style urbain et streetwear pour homme et femme.', 'products'=>[
            ['T-shirt imprimé',5000],['Casquette tendance',3000],['Veste jean',15000],['Legging sport',4000],['Sac à dos',10000]]],
        ['name'=>'Cartables & Sacs Écoliers', 'category'=>'autre', 'city'=>'Abidjan', 'country'=>'Côte d\'Ivoire', 'photo'=>'schoolbag',
         'desc'=>'Sacs et cartables solides pour la rentrée scolaire.', 'products'=>[
            ['Cartable primaire',8000],['Sac à dos collège',12000],['Trousse scolaire',3000],['Sac à roulettes',15000],['Sac de sport écolier',6000]]],
        ['name'=>'Papeterie Scolaire Plus', 'category'=>'autre', 'city'=>'Abidjan', 'country'=>'Côte d\'Ivoire', 'photo'=>'notebook',
         'desc'=>'Cahiers et fournitures scolaires à petit prix.', 'products'=>[
            ['Cahier 200 pages',1000],['Lot de 5 cahiers',4000],['Cahier de dessin',1500],['Classeur A4',3500],['Ramette de papier',5000]]],
    ];
}
function demo_review_pool() {
    return [
        'names' => ['Awa Koné','Yves Bamba','Fatou Diarra','Ibrahim Touré','Aïcha N\'Guessan','Moussa Fofana','Sarah Coulibaly','Kader Traoré','Mariam Cissé','Junior Kouassi','Aminata Bah','Rachid Ouattara','Grace Adjoumani','Salif Doumbia','Nadège Konaté'],
        'comments' => [5=>['Très satisfait, livraison rapide !','Exactement ce que je cherchais.','Qualité au rendez-vous, merci !','Je suis ravie de mon achat, je recommande.','Service impeccable, je repasserai commande.'],
                       4=>['Produit conforme à la description, je recommande.','Bon rapport qualité-prix.','Article reçu en bon état, très content.','Belle qualité, un peu long à recevoir.'],
                       3=>['Correct sans plus.','Ça fait le job, rien d\'exceptionnel.']],
    ];
}

// Vraie photo choisie par categorie (mot-cle "photo" de la boutique, voir
// demo_seed_catalog()) via loremflickr.com - certains mots-cles testes
// renvoyaient des erreurs 500 chez ce service (ex: "food", "cosmetics"),
// d'ou le choix de mots-cles alternatifs verifies fiables ("meal",
// "makeup", etc.) pour chaque categorie utilisee ici. &lock=N fixe une
// photo precise (calculee a partir du nom du produit) au lieu d'une photo
// differente a chaque affichage - chaque produit garde toujours la meme.
function demo_photo_url($keyword, $seedText) {
    $lock = (abs(crc32($seedText)) % 90) + 2; // evite lock=1 (image de secours generique cote service)
    return 'https://loremflickr.com/400/400/'.rawurlencode($keyword).'?lock='.$lock;
}

// Compte dedie (cree au besoin) qui porte TOUTES les boutiques de demonstration.
function demo_seed_user() {
    $user = q("SELECT id FROM users WHERE email=?", [DEMO_SEED_EMAIL])->fetch();
    $userId = $user['id'] ?? uid();
    if (!$user) {
        q("INSERT INTO users (id,email,password_hash,full_name,status,plan,plan_status,plan_valid_until) VALUES (?,?,?,?,?,?,?,NOW()+INTERVAL '3650 days')",
          [$userId, DEMO_SEED_EMAIL, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'Demo Seed', 'active', 'premium', 'active']);
    }
    return $userId;
}

// Catalogue "multi-devises" : de vrais articles, dans la monnaie de chaque pays
// (la devise se deduit du pays, voir currency_for_country()) - avec et sans
// centimes (EUR/MAD/GHS/NGN/USD/CAD contre XAF) pour tester les deux formats.
// 'orders' => true : quelques commandes livrees fictives, pour alimenter les
// totaux convertis du panneau admin. Memes mots-cles de photo verifies que
// demo_seed_catalog().
function demo_seed_catalog_currency() {
    return [
        ['name'=>'Paris Mode Élégante', 'category'=>'mode', 'city'=>'Paris', 'country'=>'France', 'photo'=>'clothing', 'orders'=>true,
         'desc'=>'Prêt-à-porter parisien : robes, chemises et manteaux intemporels.', 'products'=>[
            ['Robe d\'été fleurie',39.90],['Chemise en lin',34.90],['Jean slim brut',59.90],['Trench-coat beige',129.00],['Écharpe en soie',24.50]]],
        ['name'=>'Maison & Déco Lyon', 'category'=>'maison', 'city'=>'Lyon', 'country'=>'France', 'photo'=>'furniture,home', 'orders'=>true,
         'desc'=>'Objets de décoration et linge de maison au style scandinave.', 'products'=>[
            ['Lampe scandinave',34.90],['Coussin en lin',14.90],['Vase en céramique',22.00],['Plaid en laine',39.90],['Cadre photo en bois',12.50]]],
        ['name'=>'Épicerie Fine Provence', 'category'=>'alimentation', 'city'=>'Marseille', 'country'=>'France', 'photo'=>'meal', 'orders'=>true,
         'desc'=>'Produits du terroir provençal, sélectionnés chez de petits producteurs.', 'products'=>[
            ['Huile d\'olive bio 1 L',14.90],['Miel de lavande',8.50],['Herbes de Provence',4.90],['Tapenade noire',6.50],['Savon de Marseille',5.90]]],
        ['name'=>'Artisanat de Marrakech', 'category'=>'mode', 'city'=>'Marrakech', 'country'=>'Maroc', 'photo'=>'jewelry', 'orders'=>true,
         'desc'=>'Bijoux berbères et artisanat marocain fait main.', 'products'=>[
            ['Bracelet berbère en argent',350.00],['Collier touareg',480.00],['Bague en argent ciselée',220.00],['Boucles d\'oreilles',190.00],['Pendentif main de Fatma',150.00]]],
        ['name'=>'Casablanca Caftan', 'category'=>'mode', 'city'=>'Casablanca', 'country'=>'Maroc', 'photo'=>'clothing', 'orders'=>true,
         'desc'=>'Caftans, djellabas et tenues traditionnelles pour toutes les occasions.', 'products'=>[
            ['Caftan brodé',1200.00],['Djellaba femme',650.00],['Babouches en cuir',180.00],['Gandoura homme',420.00],['Foulard en soie',120.00]]],
        ['name'=>'Accra Tech Hub', 'category'=>'electronique', 'city'=>'Accra', 'country'=>'Ghana', 'photo'=>'electronics,gadget', 'orders'=>true,
         'desc'=>'Accessoires et gadgets électroniques, livrés partout au Ghana.', 'products'=>[
            ['Écouteurs Bluetooth',199.00],['Chargeur rapide 30W',65.00],['Powerbank 20000 mAh',210.00],['Enceinte portable',320.00],['Montre connectée',450.00]]],
        ['name'=>'Lagos Style House', 'category'=>'mode', 'city'=>'Lagos', 'country'=>'Nigeria', 'photo'=>'clothing', 'orders'=>true,
         'desc'=>'Mode nigériane : Ankara, Agbada et accessoires en cuir.', 'products'=>[
            ['Robe Ankara',28500],['Chemise Agbada',45000],['Sac en cuir',32000],['Sandales artisanales',15500],['Bonnet Aso Oke',9500]]],
        ['name'=>'Brooklyn Sneakers', 'category'=>'mode', 'city'=>'New York', 'country'=>'États-Unis', 'photo'=>'shoes', 'orders'=>true,
         'desc'=>'Sneakers, baskets et chaussures de ville, expédiées dans tout le pays.', 'products'=>[
            ['Sneakers blanches',79.99],['Baskets running',94.50],['Bottines en cuir',129.00],['Sandales d\'été',34.99],['Pack de 6 paires de chaussettes',15.99]]],
        ['name'=>'Douala Beauté Naturelle', 'category'=>'beaute', 'city'=>'Douala', 'country'=>'Cameroun', 'photo'=>'makeup', 'orders'=>true,
         'desc'=>'Soins et cosmétiques naturels camerounais.', 'products'=>[
            ['Beurre de karité pur',4500],['Huile de coco vierge',3500],['Rouge à lèvres mat',6000],['Sérum visage naturel',12000],['Parfum d\'ambiance',8000]]],
        ['name'=>'Montréal Sport Plus', 'category'=>'autre', 'city'=>'Montréal', 'country'=>'Canada', 'photo'=>'sport', 'orders'=>true,
         'desc'=>'Équipement de sport et de fitness pour s\'entraîner à la maison.', 'products'=>[
            ['Tapis de yoga',34.99],['Haltères 5 kg (la paire)',44.99],['Bouteille isotherme',24.50],['Sac de sport',59.00],['Corde à sauter',14.99]]],
    ];
}

// Quelques commandes LIVREES fictives (3 a 5) sur les 3 dernieres semaines
// pour une boutique de demo - de quoi voir des totaux, des classements et la
// conversion des devises dans le panneau admin. Supprimees avec la boutique
// (voir admin_delete_demo_data()).
function demo_seed_orders($btId, $currency, $items) {
    $names = demo_review_pool()['names'];
    $dec = currency_decimals($currency);
    $made = 0;
    for ($i = 0, $n = rand(3, 5); $i < $n; $i++) {
        $picked = (array)array_rand($items, min(rand(1, 2), count($items)));
        $lines = []; $subtotal = 0;
        foreach ($picked as $k) {
            $qty = rand(1, 3);
            $lines[] = [$items[$k][0], $items[$k][1], $items[$k][2], $qty];
            $subtotal += $items[$k][2] * $qty;
        }
        $subtotal = round($subtotal, $dec);
        $fee = rand(0, 1) ? round($subtotal * 0.05, $dec) : 0;
        $total = round($subtotal + $fee, $dec);
        $orderId = uid(); $daysAgo = rand(0, 20);
        q("INSERT INTO orders (id,boutique_id,ref,status,payment_method,subtotal,delivery_fee_charged,total,customer_name,customer_phone,customer_address,delivery_method,created_at,delivered_at)
           VALUES (?,?,?,'delivered','cod',?,?,?,?,?,?,'delivery', NOW() - (?::text || ' days')::interval, NOW() - (?::text || ' days')::interval)",
          [$orderId, $btId, order_ref(), $subtotal, $fee, $total, $names[array_rand($names)], '06'.rand(10000000, 99999999), 'Adresse de demonstration', $daysAgo, $daysAgo]);
        foreach ($lines as [$pid, $pname, $price, $qty]) {
            q("INSERT INTO order_items (id,order_id,product_id,product_name,unit_price,unit_cost,qty) VALUES (?,?,?,?,?,0,?)", [uid(), $orderId, $pid, $pname, $price, $qty]);
        }
        $made++;
    }
    return $made;
}

// Cree les boutiques d'un catalogue de demo sous le compte demo. IDEMPOTENT :
// une boutique du meme nom deja presente sur ce compte est sautee, donc
// recliquer sur un bouton ne cree jamais de doublons. Devise = celle de
// l'entree si elle en donne une, sinon celle du pays.
function demo_seed_shops($catalog, $userId) {
    $reviewPool = demo_review_pool();
    $res = ['boutiques' => 0, 'products' => 0, 'reviews' => 0, 'orders' => 0, 'skipped' => 0];
    foreach ($catalog as $b) {
        if (q("SELECT 1 FROM boutiques WHERE owner_user_id=? AND name=?", [$userId, $b['name']])->fetch()) { $res['skipped']++; continue; }
        $slug = unique_boutique_slug(slugify($b['name']));
        $btId = uid();
        $currency = $b['currency'] ?? currency_for_country($b['country']);
        $dec = currency_decimals($currency);
        $logoUrl = demo_photo_url($b['photo'], $b['name'].'-logo');
        q("INSERT INTO boutiques (id,owner_user_id,slug,name,description,logo_url,category,city,country,public_listed,status,currency,cod_enabled)
           VALUES (?,?,?,?,?,?,?,?,?,1,'active',?,1)",
          [$btId, $userId, $slug, $b['name'], $b['desc'], $logoUrl, $b['category'], $b['city'], $b['country'], $currency]);
        $res['boutiques']++;
        $boutiqueProductIds = []; $orderItems = [];
        foreach ($b['products'] as $index => [$pname, $price]) {
            $pSlug = unique_product_slug($btId, slugify($pname));
            $pId = uid();
            $mainPhoto = demo_photo_url($b['photo'], $pname.'-0');
            // Un produit sur deux affiche un prix barre (compare_at_price)
            // un peu plus haut - donne une impression de promotions actives.
            // Monnaie sans centimes : +1000 a +3000 ; avec centimes : +10 a
            // +30 %, arrondi aux centimes.
            $compareAt = ($index % 2 === 0) ? ($dec === 0 ? $price + (rand(2,6) * 500) : round($price * (1 + rand(10, 30) / 100), $dec)) : null;
            q("INSERT INTO products (id,boutique_id,name,price,compare_at_price,stock_qty,status,slug,is_physical,track_inventory,image_url)
               VALUES (?,?,?,?,?,?,'active',?,1,1,?)",
              [$pId, $btId, $pname, $price, $compareAt, rand(5,40), $pSlug, $mainPhoto]);
            // Galerie de 4 photos (position 0 = principale), une par seed.
            q("INSERT INTO product_images (id,product_id,boutique_id,data,position,is_primary) VALUES (?,?,?,?,0,1)",
              [uid(), $pId, $btId, $mainPhoto]);
            for ($i = 1; $i <= 3; $i++) {
                q("INSERT INTO product_images (id,product_id,boutique_id,data,position,is_primary) VALUES (?,?,?,?,?,0)",
                  [uid(), $pId, $btId, demo_photo_url($b['photo'], $pname.'-'.$i), $i]);
            }
            $res['products']++;
            $boutiqueProductIds[] = $pId;
            $orderItems[] = [$pId, $pname, $price];
            // 1 a 2 avis par produit, majoritairement positifs, deja approuves.
            for ($r = 0, $reviewCount = rand(1, 2); $r < $reviewCount; $r++) {
                $rating = rand(1, 10) <= 8 ? (rand(0,1) ? 5 : 4) : 3;
                $name = $reviewPool['names'][array_rand($reviewPool['names'])];
                $comment = $reviewPool['comments'][$rating][array_rand($reviewPool['comments'][$rating])];
                q("INSERT INTO product_reviews (id,boutique_id,product_id,customer_name,rating,comment,status) VALUES (?,?,?,?,?,?,'approved')",
                  [uid(), $btId, $pId, $name, $rating, $comment]);
                $res['reviews']++;
            }
        }
        // Produits associes (upsell) : jusqu'a 3 autres produits de la MEME boutique.
        foreach ($boutiqueProductIds as $pid) {
            $others = array_values(array_diff($boutiqueProductIds, [$pid]));
            shuffle($others);
            q("UPDATE products SET related_product_ids=? WHERE id=?", [json_encode(array_slice($others, 0, 3)), $pid]);
        }
        if (!empty($b['orders'])) $res['orders'] += demo_seed_orders($btId, $currency, $orderItems);
    }
    return $res;
}
function demo_seed_message($res) {
    if ($res['boutiques'] === 0 && $res['skipped'] > 0) return 'Ces boutiques de demonstration existent deja - rien de nouveau a creer.';
    return $res['boutiques'].' boutiques, '.$res['products'].' produits, '.$res['reviews'].' avis'.($res['orders'] ? ' et '.$res['orders'].' commandes livrees' : '').' de demonstration crees'.($res['skipped'] ? ' ('.$res['skipped'].' deja existantes ignorees)' : '').'.';
}
function admin_seed_demo_data() {
    $res = demo_seed_shops(demo_seed_catalog(), demo_seed_user());
    ok(['boutiques_created' => $res['boutiques'], 'products_created' => $res['products'], 'reviews_created' => $res['reviews']], demo_seed_message($res));
}
function admin_seed_demo_currency() {
    $res = demo_seed_shops(demo_seed_catalog_currency(), demo_seed_user());
    ok(['boutiques_created' => $res['boutiques'], 'products_created' => $res['products'], 'reviews_created' => $res['reviews'], 'orders_created' => $res['orders']], demo_seed_message($res));
}

function admin_delete_demo_data() {
    $user = q("SELECT id FROM users WHERE email=?", [DEMO_SEED_EMAIL])->fetch();
    if (!$user) { ok(['boutiques_deleted' => 0], 'Aucune donnee de demonstration trouvee.'); return; }
    $boutiques = q("SELECT id FROM boutiques WHERE owner_user_id=?", [$user['id']])->fetchAll();
    foreach ($boutiques as $bt) {
        // Commandes de la boutique (fictives OU passees en test sur sa
        // vitrine) et tout ce qui s'y rattache - sinon elles resteraient en
        // base sans boutique et fausseraient les compteurs du panneau admin.
        $orderIds = q("SELECT id FROM orders WHERE boutique_id=?", [$bt['id']])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($orderIds as $oid) q("DELETE FROM order_items WHERE order_id=?", [$oid]);
        foreach (['delivery_assignments', 'orders', 'customers', 'visits', 'abandoned_carts', 'activity_log', 'contact_messages', 'newsletter_subscribers'] as $tbl) {
            q("DELETE FROM $tbl WHERE boutique_id=?", [$bt['id']]);
        }
        $productIds = q("SELECT id FROM products WHERE boutique_id=?", [$bt['id']])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($productIds as $pid) {
            q("DELETE FROM product_variants WHERE product_id=?", [$pid]);
            q("DELETE FROM product_images WHERE product_id=?", [$pid]);
            q("DELETE FROM product_digital_codes WHERE product_id=?", [$pid]);
            q("DELETE FROM product_reviews WHERE product_id=?", [$pid]);
        }
        q("DELETE FROM products WHERE boutique_id=?", [$bt['id']]);
        q("DELETE FROM boutiques WHERE id=?", [$bt['id']]);
    }
    q("DELETE FROM users WHERE id=?", [$user['id']]);
    ok(['boutiques_deleted' => count($boutiques)], count($boutiques).' boutiques de demonstration supprimees.');
}

// Montant reellement paye pour une demande, selon son billing_cycle -
// 'annual' = 12x le tarif mensuel (paye pour 13 mois d'acces), 'free_trial'
// = 0 (aucun argent n'a change de mains), 'monthly'/absent = tarif normal.
// Source unique utilisee par les stats de revenu ET le calcul de
// commission de parrainage, pour qu'un abonnement annuel ou gratuit ne
// soit jamais compte comme un mois normal a l'un des deux endroits sans
// l'autre.
function subscription_request_amount($req) {
    $base = PLANS[$req['plan']]['price'] ?? 0;
    $cycle = $req['billing_cycle'] ?? 'monthly';
    if ($cycle === 'annual') return $base * 12;
    if ($cycle === 'free_trial') return 0;
    return $base;
}
// Duree accordee a l'approbation, selon le billing_cycle - 390 jours
// (13x30) pour l'annuel ("1 mois offert"), 30 jours sinon.
function subscription_request_days($req) {
    return ($req['billing_cycle'] ?? 'monthly') === 'annual' ? 390 : 30;
}

function admin_subscription_approve() {
    $b = body();
    $req = q("SELECT * FROM subscription_requests WHERE id=?", [$b['id'] ?? ''])->fetch();
    if (!$req) fail('Demande introuvable', 404);
    // Prolonge a partir de MAINTENANT (pas cumule sur l'ancienne date) - si
    // un compte est deja expire depuis longtemps, le paiement repart d'une
    // periode pleine a partir d'aujourd'hui plutot que de rester bloque a
    // cause d'un cumul depuis une tres vieille date. Duree selon le cycle
    // (30 jours, ou 390 pour l'annuel "1 mois offert" - voir
    // subscription_request_days()).
    $days = subscription_request_days($req);
    q("UPDATE users SET plan=?, plan_status='active', plan_valid_until=NOW() + (?::text || ' days')::interval WHERE id=?", [$req['plan'], $days, $req['user_id']]);
    q("UPDATE subscription_requests SET status='approved', reviewed_at=NOW() WHERE id=?", [$req['id']]);
    // Commission de parrainage (10% du montant reellement paye) si ce
    // compte a ete recrute via un lien d'affiliation - une seule fois par
    // abonnement approuve (subscription_request_id), jamais recalculee si
    // le meme plan est de nouveau approuve plus tard.
    $referredUser = q("SELECT referred_by FROM users WHERE id=?", [$req['user_id']])->fetch();
    if ($referredUser && $referredUser['referred_by']) {
        $amount = round(subscription_request_amount($req) * 0.10, 2);
        if ($amount > 0) {
            q("INSERT INTO referral_commissions (id,referrer_user_id,referred_user_id,subscription_request_id,plan,amount) VALUES (?,?,?,?,?,?)",
              [uid(), $referredUser['referred_by'], $req['user_id'], $req['id'], $req['plan'], $amount]);
        }
    }
    ok(null, 'Plan active');
}

function admin_subscription_reject() {
    $b = body();
    q("UPDATE subscription_requests SET status='rejected', reviewed_at=NOW() WHERE id=?", [$b['id'] ?? '']);
    ok(null, 'Demande rejetee');
}

function admin_payouts_pending() {
    ok(q("SELECT rp.*, u.email FROM referral_payouts rp JOIN users u ON u.id=rp.user_id
          WHERE rp.status='requested' ORDER BY rp.created_at ASC")->fetchAll());
}

function admin_payout_mark_paid() {
    $b = body();
    $payout = q("SELECT * FROM referral_payouts WHERE id=?", [$b['id'] ?? ''])->fetch();
    if (!$payout) fail('Retrait introuvable', 404);
    q("UPDATE referral_payouts SET status='paid', paid_at=NOW() WHERE id=?", [$payout['id']]);
    q("UPDATE referral_commissions SET status='paid' WHERE referrer_user_id=? AND status='requested'", [$payout['user_id']]);
    ok(null, 'Retrait marque paye');
}

// ============================================================
// INTEGRATIONS — import de commandes depuis une feuille Google Sheets
// publiee en CSV. Reserve au proprietaire/admin de la boutique (memes
// donnees sensibles qu'un parametre). Pas de synchronisation automatique en
// arriere-plan (aucun worker planifie sur cet hebergement) : seul le bouton
// "Importer maintenant" declenche une lecture, a la demande.
// ============================================================
function route_integrations($action) {
    $pl = owner_auth();
    switch ($action) {
        case 'sheet_get':    integrations_sheet_get($pl); break;
        case 'sheet_save':   integrations_sheet_save($pl); break;
        case 'sheet_import': integrations_sheet_import($pl); break;
        default: fail('Action inconnue', 404);
    }
}

function integrations_sheet_get($pl) {
    $bt = require_boutique_admin($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(['sheet_url' => $bt['sheet_url'], 'sheet_sync_enabled' => (bool)$bt['sheet_sync_enabled']]);
}

function integrations_sheet_save($pl) {
    $b = body();
    $bt = require_boutique_admin($b['boutique_id'] ?? '', $pl['sub']);
    q("UPDATE boutiques SET sheet_url=?, sheet_sync_enabled=? WHERE id=?",
      [trim($b['sheet_url'] ?? ''), (int)!!($b['sheet_sync_enabled'] ?? 0), $bt['id']]);
    ok(null, 'Parametres enregistres');
}

// Lit une valeur de ligne CSV en essayant plusieurs noms de colonne possibles
// (les variantes usuelles - "tel"/"telephone", "qty"/"quantite" - sont
// acceptees sans que l'ordre des colonnes compte).
function csv_alias($row, $aliases) {
    foreach ($aliases as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') return trim((string)$row[$key]);
    }
    return '';
}

function integrations_sheet_import($pl) {
    $bt = require_boutique_admin(bg('boutique_id'), $pl['sub']);
    $result = sheet_import_run($bt, $pl['sub']);
    if (isset($result['error'])) fail($result['error']);
    ok($result, $result['imported'].' commande(s) importee(s), '.count($result['skipped']).' ligne(s) ignoree(s)');
}

// Coeur de l'import CSV, partage entre le bouton "Importer maintenant"
// (marchand connecte, $actorUserId renseigne pour l'historique) et le cron
// horaire cron_sheet_sync() (aucun marchand connecte, $actorUserId null -
// voir log_activity()).
function sheet_import_run($bt, $actorUserId = null) {
    // Retourne toujours un tableau (jamais fail(), qui coupe la reponse
    // HTTP) - le cron traite plusieurs boutiques d'affilee et une feuille
    // en erreur pour l'une ne doit pas interrompre les autres.
    $url = trim($bt['sheet_url'] ?? '');
    if ($url === '') return ['imported' => 0, 'skipped' => [], 'error' => 'Aucun lien de feuille configure'];
    $context = stream_context_create(['http' => ['timeout' => 15], 'https' => ['timeout' => 15]]);
    $csvRaw = @file_get_contents($url, false, $context);
    if ($csvRaw === false || trim($csvRaw) === '') {
        return ['imported' => 0, 'skipped' => [], 'error' => 'Impossible de recuperer la feuille (verifiez que le lien est bien publie en CSV et accessible publiquement)'];
    }
    $lines = preg_split('/\r\n|\r|\n/', trim($csvRaw));
    if (count($lines) < 2) return ['imported' => 0, 'skipped' => [], 'error' => 'La feuille est vide (juste l\'entete ou aucune ligne)'];
    $header = array_map(fn($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));

    $groups = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cells = str_getcsv($line);
        $row = [];
        foreach ($header as $i => $h) { $row[$h] = $cells[$i] ?? ''; }
        $extRef = csv_alias($row, ['id_commande', 'order id', 'commande', 'id']);
        if ($extRef === '') continue; // colonne obligatoire manquante : ligne ignoree silencieusement
        $groups[$extRef][] = $row;
    }

    $imported = 0; $skipped = [];
    foreach ($groups as $extRef => $groupRows) {
        $already = q("SELECT id FROM orders WHERE boutique_id=? AND external_ref=?", [$bt['id'], $extRef])->fetch();
        if ($already) continue; // deja importee - jamais deux fois la meme ligne
        $first = $groupRows[0];
        $client = csv_alias($first, ['client', 'nom', 'name']);
        $phone = csv_alias($first, ['telephone', 'tel', 'phone']);
        if ($client === '' || $phone === '') { $skipped[] = "$extRef: client ou telephone manquant"; continue; }

        $items = []; $subtotal = 0; $lineOk = true; $reason = '';
        foreach ($groupRows as $row) {
            $sku = csv_alias($row, ['sku', 'reference']);
            if ($sku === '') { $lineOk = false; $reason = 'SKU manquant'; break; }
            $product = q("SELECT * FROM products WHERE boutique_id=? AND sku=?", [$bt['id'], $sku])->fetch();
            if (!$product) { $lineOk = false; $reason = "SKU '$sku' introuvable dans le catalogue"; break; }
            $qty = max(1, (int)(csv_alias($row, ['quantite', 'qty', 'quantity']) ?: 1));
            $unitPriceRaw = csv_alias($row, ['prix_unitaire', 'prix', 'price']);
            $unitPrice = $unitPriceRaw !== '' ? (float)$unitPriceRaw : (float)$product['price'];
            $items[] = ['product' => $product, 'qty' => $qty, 'unit_price' => $unitPrice];
            $subtotal += $unitPrice * $qty;
        }
        if (!$lineOk) { $skipped[] = "$extRef: $reason"; continue; }

        $deliveryFee = (float)(csv_alias($first, ['frais_livraison', 'frais de livraison']) ?: 0);
        $address = trim(csv_alias($first, ['adresse']).' '.csv_alias($first, ['quartier']).' '.csv_alias($first, ['ville']));
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $customerRow = q("SELECT id FROM customers WHERE boutique_id=? AND phone=?", [$bt['id'], $phone])->fetch();
            if ($customerRow) {
                $customerId = $customerRow['id'];
                q("UPDATE customers SET name=?, address=? WHERE id=?", [$client, $address, $customerId]);
            } else {
                $customerId = uid();
                q("INSERT INTO customers (id,boutique_id,name,phone,email,address) VALUES (?,?,?,?,?,?)",
                  [$customerId, $bt['id'], $client, $phone, csv_alias($first, ['email']), $address]);
            }
            $orderId = uid(); $ref = order_ref();
            $total = $subtotal + $deliveryFee;
            q("INSERT INTO orders (id,boutique_id,customer_id,ref,external_ref,status,payment_method,subtotal,delivery_fee_charged,total,
               customer_name,customer_phone,customer_address) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [$orderId, $bt['id'], $customerId, $ref, $extRef, 'pending', 'cod', $subtotal, $deliveryFee, $total, $client, $phone, $address]);
            foreach ($items as $it) {
                q("INSERT INTO order_items (id,order_id,product_id,product_name,unit_price,unit_cost,qty) VALUES (?,?,?,?,?,?,?)",
                  [uid(), $orderId, $it['product']['id'], $it['product']['name'], $it['unit_price'], $it['product']['cost_price'] ?? 0, $it['qty']]);
                if ($it['product']['track_inventory']) {
                    q("UPDATE products SET stock_qty = stock_qty - ? WHERE id=?", [$it['qty'], $it['product']['id']]);
                }
            }
            q("INSERT INTO delivery_assignments (id,order_id,boutique_id,status) VALUES (?,?,?,?)", [uid(), $orderId, $bt['id'], 'to_assign']);
            $pdo->commit();
            $imported++;
            log_activity($bt['id'], 'Commande importee depuis Google Sheets: '.$ref, $actorUserId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $skipped[] = "$extRef: erreur d'import";
        }
    }
    return ['imported' => $imported, 'skipped' => $skipped];
}

// ============================================================
// CRON — taches planifiees declenchees par un service externe (cron-job.org
// ou equivalent), aucun worker en arriere-plan n'existe sur cet hebergement.
// Protegee par CRON_KEY (jamais un token utilisateur : personne n'est
// connecte quand le service de cron appelle cette route).
// ============================================================
function route_cron($action) {
    $key = $_GET['key'] ?? '';
    if (!hash_equals((string)CRON_KEY, (string)$key)) fail('Non autorise', 403);
    switch ($action) {
        case 'abandoned_reminders': cron_abandoned_reminders(); break;
        case 'stock_alerts':        cron_stock_alerts(); break;
        case 'sheet_sync':          cron_sheet_sync(); break;
        default: fail('Action inconnue', 404);
    }
}

// Une seule relance par panier (reminded_at), envoyee entre 30 minutes et 48h
// apres capture - au-dela le client a probablement deja renonce ou commande
// ailleurs, en dessous on le derange trop tot.
function cron_abandoned_reminders() {
    $carts = q("SELECT ac.*, b.slug, b.name AS boutique_name, b.currency
                FROM abandoned_carts ac
                JOIN boutiques b ON b.id = ac.boutique_id
                JOIN abandoned_settings s ON s.boutique_id = ac.boutique_id
                WHERE ac.converted=0 AND ac.reminded_at IS NULL AND s.customer_reminder_enabled=1
                AND ac.captured_at <= NOW() - INTERVAL '30 minutes'
                AND ac.captured_at >= NOW() - INTERVAL '48 hours'")->fetchAll();
    $sent = 0;
    foreach ($carts as $c) {
        $total = money_fmt($c['total'], $c['currency']).' '.($c['currency'] ?: 'XOF');
        $message = "Bonjour, vous avez laisse des articles dans votre panier chez ".$c['boutique_name']." (".$total."). Revenez finaliser votre commande !";
        if ($c['email']) send_email($c['email'], 'Votre panier vous attend - '.$c['boutique_name'], $message);
        q("UPDATE abandoned_carts SET reminded_at=NOW() WHERE id=?", [$c['id']]);
        $sent++;
    }
    ok(['reminders_sent' => $sent]);
}

// Au plus une alerte par boutique toutes les 20h (voir last_stock_alert_at) -
// evite de spammer le marchand a chaque appel du cron (typiquement toutes
// les heures) tant que le stock reste bas.
function cron_stock_alerts() {
    $boutiques = q("SELECT * FROM boutiques WHERE status='active' AND stock_alert_enabled=1
                     AND (last_stock_alert_at IS NULL OR last_stock_alert_at <= NOW() - INTERVAL '20 hours')")->fetchAll();
    $sent = 0;
    foreach ($boutiques as $bt) {
        $low = q("SELECT name, stock_qty, low_stock_threshold FROM products
                   WHERE boutique_id=? AND status='active' AND track_inventory=1 AND stock_qty <= low_stock_threshold
                   ORDER BY stock_qty ASC LIMIT 30", [$bt['id']])->fetchAll();
        // Meme seuil (low_stock_threshold) reutilise pour le pool de codes
        // numeriques - seuls les produits ayant deja au moins un code dans
        // leur pool sont concernes (poolSize=0 = le marchand n'utilise pas
        // les codes pour ce produit, rien a signaler).
        $lowCodes = q("SELECT p.name,
                         (SELECT COUNT(*) FROM product_digital_codes c WHERE c.product_id=p.id AND c.status='available') AS available_codes
                       FROM products p
                       WHERE p.boutique_id=? AND p.status='active' AND p.is_digital=1
                         AND EXISTS (SELECT 1 FROM product_digital_codes c WHERE c.product_id=p.id)
                         AND (SELECT COUNT(*) FROM product_digital_codes c WHERE c.product_id=p.id AND c.status='available') <= p.low_stock_threshold
                       ORDER BY available_codes ASC LIMIT 30", [$bt['id']])->fetchAll();
        if (!$low && !$lowCodes) continue;
        $messageParts = [];
        if ($low) {
            $lines = array_map(fn($p) => '- '.$p['name'].' : '.$p['stock_qty'].' restant(s)', $low);
            $messageParts[] = "Stock limite sur ".count($low)." produit(s) physique(s) :\n".implode("\n", $lines);
        }
        if ($lowCodes) {
            $lines = array_map(fn($p) => '- '.$p['name'].' : '.$p['available_codes'].' code(s) disponible(s)', $lowCodes);
            $messageParts[] = "Stock de codes numeriques limite sur ".count($lowCodes)." produit(s) :\n".implode("\n", $lines);
        }
        $message = implode("\n\n", $messageParts)."\n\nBoutique : ".$bt['name'];
        $settings = q("SELECT notify_order_email, notify_email FROM boutiques WHERE id=?", [$bt['id']])->fetch();
        if ($settings['notify_order_email']) {
            $to = $settings['notify_email'] ?: (q("SELECT email FROM users WHERE id=?", [$bt['owner_user_id']])->fetchColumn() ?: null);
            if ($to) send_email($to, 'Alerte stock limite - '.$bt['name'], $message);
        }
        q("UPDATE boutiques SET last_stock_alert_at=NOW() WHERE id=?", [$bt['id']]);
        $sent++;
    }
    ok(['boutiques_alerted' => $sent]);
}

// Import automatique horaire pour chaque boutique qui a coche "Activer
// cette integration" (sheet_sync_enabled) - reutilise exactement le meme
// import que le bouton "Importer maintenant" (voir sheet_import_run()),
// juste applique a toutes les boutiques concernees d'un coup plutot qu'a
// une seule a la demande. Une feuille en erreur pour une boutique
// n'empeche pas l'import des autres.
function cron_sheet_sync() {
    $boutiques = q("SELECT * FROM boutiques WHERE status='active' AND sheet_sync_enabled=1 AND sheet_url IS NOT NULL AND sheet_url<>''")->fetchAll();
    $totalImported = 0; $results = [];
    foreach ($boutiques as $bt) {
        $r = sheet_import_run($bt, null);
        $totalImported += $r['imported'];
        $results[] = ['boutique' => $bt['name'], 'imported' => $r['imported'], 'skipped' => count($r['skipped']), 'error' => $r['error'] ?? null];
    }
    ok(['boutiques_synced' => count($boutiques), 'total_imported' => $totalImported, 'details' => $results]);
}
