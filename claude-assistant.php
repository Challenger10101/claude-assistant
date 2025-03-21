<?php
/**
 * Plugin Name: Claude AI Assistant
 * Plugin URI: https://votre-site.com
 * Description: Interface de chat avec Claude intégrée à WordPress avec support vocal et LangChain
 * Version: 1.0.0
 * Author: Auto-généré par Claude
 * Text Domain: claude-assistant
 */

// Sécurité: empêcher l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

class Claude_Assistant {
    private $plugin_path;
    private $plugin_url;
    private $version = '1.0.0';
    private $anthropic_api_key = '';
    private $n8n_webhook_url = '';

    /**
     * Initialisation du plugin
     */
    public function __construct() {
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);
        
        // Hooks d'initialisation
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // Créer un shortcode pour intégrer le chat sur n'importe quelle page
        add_shortcode('claude_chat', array($this, 'display_chat_interface'));
        
        // Ajouter l'endpoint REST API pour les communications avec n8n
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Installer les assets lors de l'activation
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
    }
    
    /**
     * Activation du plugin: créer les dossiers nécessaires et tables en base de données
     */
    public function activate_plugin() {
        // Créer un répertoire pour stocker les fichiers audio temporaires
        $upload_dir = wp_upload_dir();
        $claude_dir = $upload_dir['basedir'] . '/claude-assistant';
        
        if (!file_exists($claude_dir)) {
            wp_mkdir_p($claude_dir);
        }
        
        // Créer la table pour stocker l'historique des conversations
        global $wpdb;
        $table_name = $wpdb->prefix . 'claude_conversations';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id mediumint(9) NOT NULL,
            conversation_id varchar(255) NOT NULL,
            message text NOT NULL,
            response text NOT NULL,
            message_type varchar(50) DEFAULT 'text',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Ajouter l'option pour le modèle Claude par défaut
        add_option('claude_model', 'claude-3-opus-20240229');
        add_option('claude_temperature', '0.7');
    }
    
    /**
     * Ajouter le menu d'administration
     */
    public function add_admin_menu() {
        add_menu_page(
            'Claude Assistant',
            'Claude Assistant',
            'manage_options',
            'claude-assistant',
            array($this, 'display_admin_page'),
            $this->plugin_url . 'assets/images/claude-icon.png',
            30
        );
        
        add_submenu_page(
            'claude-assistant',
            'Paramètres',
            'Paramètres',
            'manage_options',
            'claude-assistant-settings',
            array($this, 'display_settings_page')
        );
        
        add_submenu_page(
            'claude-assistant',
            'Historique',
            'Historique',
            'manage_options',
            'claude-assistant-history',
            array($this, 'display_history_page')
        );
        
        add_submenu_page(
            'claude-assistant',
            'Tableau de bord',
            'Tableau de bord',
            'manage_options',
            'claude-assistant-dashboard',
            array($this, 'display_dashboard_page')
        );
    }
    
    /**
     * Enregistrer les paramètres
     */
    public function register_settings() {
        register_setting('claude_assistant_settings', 'claude_api_key');
        register_setting('claude_assistant_settings', 'n8n_webhook_url');
        register_setting('claude_assistant_settings', 'claude_assistant_access_roles', array(
            'default' => array('administrator'),
            'sanitize_callback' => array($this, 'sanitize_roles')
        ));
        register_setting('claude_assistant_settings', 'claude_model');
        register_setting('claude_assistant_settings', 'claude_temperature');
        
        add_settings_section(
            'claude_assistant_main_section',
            'Configuration principale',
            array($this, 'settings_section_callback'),
            'claude-assistant-settings'
        );
        
        add_settings_field(
            'claude_api_key',
            'Clé API Anthropic',
            array($this, 'api_key_field_callback'),
            'claude-assistant-settings',
            'claude_assistant_main_section'
        );
        
        add_settings_field(
            'n8n_webhook_url',
            'URL Webhook n8n',
            array($this, 'webhook_url_field_callback'),
            'claude-assistant-settings',
            'claude_assistant_main_section'
        );
        
        add_settings_field(
            'claude_assistant_access_roles',
            'Rôles ayant accès',
            array($this, 'access_roles_field_callback'),
            'claude-assistant-settings',
            'claude_assistant_main_section'
        );
        
        add_settings_field(
            'claude_model',
            'Modèle Claude',
            array($this, 'model_field_callback'),
            'claude-assistant-settings',
            'claude_assistant_main_section'
        );
        
        add_settings_field(
            'claude_temperature',
            'Température',
            array($this, 'temperature_field_callback'),
            'claude-assistant-settings',
            'claude_assistant_main_section'
        );
    }
    
    /**
     * Sanitize roles
     */
    public function sanitize_roles($roles) {
        if (!is_array($roles)) {
            return array('administrator');
        }
        return $roles;
    }
    
    /**
     * Section de paramètres
     */
    public function settings_section_callback() {
        echo '<p>Configurez les paramètres pour connecter Claude Assistant à votre infrastructure.</p>';
    }
    
    /**
     * Champ de clé API
     */
    public function api_key_field_callback() {
        $value = get_option('claude_api_key', '');
        echo '<input type="password" id="claude_api_key" name="claude_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Votre clé API Anthropic pour Claude</p>';
    }
    
    /**
     * Champ d'URL de webhook
     */
    public function webhook_url_field_callback() {
        $value = get_option('n8n_webhook_url', '');
        echo '<input type="url" id="n8n_webhook_url" name="n8n_webhook_url" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">URL de votre webhook n8n pour l\'intégration</p>';
    }
    
    /**
     * Champ des rôles d'accès
     */
    public function access_roles_field_callback() {
        $roles = get_editable_roles();
        $selected_roles = get_option('claude_assistant_access_roles', array('administrator'));
        
        foreach ($roles as $role_key => $role) {
            echo '<label>';
            echo '<input type="checkbox" name="claude_assistant_access_roles[]" value="' . esc_attr($role_key) . '" ' . 
                (in_array($role_key, $selected_roles) ? 'checked="checked"' : '') . ' />';
            echo esc_html($role['name']);
            echo '</label><br />';
        }
        echo '<p class="description">Sélectionnez les rôles qui peuvent accéder à Claude Assistant</p>';
    }
    
    /**
     * Champ de modèle Claude
     */
    public function model_field_callback() {
        $value = get_option('claude_model', 'claude-3-opus-20240229');
        echo '<select id="claude_model" name="claude_model">';
        echo '<option value="claude-3-opus-20240229" ' . selected($value, 'claude-3-opus-20240229', false) . '>Claude 3 Opus (Haute qualité)</option>';
        echo '<option value="claude-3-sonnet-20240229" ' . selected($value, 'claude-3-sonnet-20240229', false) . '>Claude 3 Sonnet (Équilibré)</option>';
        echo '<option value="claude-3-haiku-20240307" ' . selected($value, 'claude-3-haiku-20240307', false) . '>Claude 3 Haiku (Rapide)</option>';
        echo '</select>';
        echo '<p class="description">Sélectionnez le modèle Claude à utiliser pour les réponses</p>';
    }
    
    /**
     * Champ de température
     */
    public function temperature_field_callback() {
        $value = get_option('claude_temperature', '0.7');
        echo '<input type="range" id="claude_temperature" name="claude_temperature" min="0" max="1" step="0.1" value="' . esc_attr($value) . '" />';
        echo '<span id="temperature_value">' . esc_html($value) . '</span>';
        echo '<p class="description">Contrôle la créativité des réponses (0 = précis, 1 = créatif)</p>';
        echo '<script>
            jQuery(document).ready(function($) {
                $("#claude_temperature").on("input", function() {
                    $("#temperature_value").text($(this).val());
                });
            });
        </script>';
    }
    
    /**
     * Page d'administration principale
     */
    public function display_admin_page() {
        // Vérifier si l'utilisateur a accès
        if (!$this->user_has_access()) {
            wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', 'claude-assistant'));
        }
        
        include_once($this->plugin_path . 'templates/admin-page.php');
    }
    
    /**
     * Page de paramètres
     */
    public function display_settings_page() {
        // Vérifier si l'utilisateur a accès
        if (!$this->user_has_access()) {
            wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', 'claude-assistant'));
        }
        
        include_once($this->plugin_path . 'templates/settings-page.php');
    }
    
    /**
     * Page d'historique
     */
    public function display_history_page() {
        // Vérifier si l'utilisateur a accès
        if (!$this->user_has_access()) {
            wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', 'claude-assistant'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'claude_conversations';
        $user_id = get_current_user_id();
        
        // Récupérer les conversations par ID de conversation
        $conversations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT conversation_id, MAX(created_at) as last_message
                FROM $table_name
                WHERE user_id = %d
                GROUP BY conversation_id
                ORDER BY last_message DESC",
                $user_id
            )
        );
        
        echo '<div class="wrap">';
        echo '<h1>Historique des conversations</h1>';
        
        if (empty($conversations)) {
            echo '<p>Aucune conversation trouvée.</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>ID de conversation</th><th>Dernier message</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($conversations as $conversation) {
                $view_url = add_query_arg(array(
                    'page' => 'claude-assistant-history',
                    'conversation' => $conversation->conversation_id
                ), admin_url('admin.php'));
                
                echo '<tr>';
                echo '<td>' . esc_html($conversation->conversation_id) . '</td>';
                echo '<td>' . esc_html($conversation->last_message) . '</td>';
                echo '<td><a href="' . esc_url($view_url) . '">Voir</a></td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
        }
        
        // Afficher une conversation spécifique si demandée
        if (isset($_GET['conversation'])) {
            $conversation_id = sanitize_text_field($_GET['conversation']);
            
            $messages = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name
                    WHERE user_id = %d AND conversation_id = %s
                    ORDER BY created_at ASC",
                    $user_id,
                    $conversation_id
                )
            );
            
            echo '<h2>Conversation: ' . esc_html($conversation_id) . '</h2>';
            echo '<div class="claude-conversation-history">';
            
            foreach ($messages as $message) {
                echo '<div class="message-container">';
                echo '<div class="user-message">';
                echo '<strong>Vous:</strong><br>';
                echo esc_html($message->message);
                echo '<span class="message-time">' . esc_html($message->created_at) . '</span>';
                echo '</div>';
                echo '<div class="claude-message">';
                echo '<strong>Claude:</strong><br>';
                echo esc_html($message->response);
                echo '</div>';
                echo '</div>';
            }
            
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Page de tableau de bord
     */
    public function display_dashboard_page() {
        // Vérifier si l'utilisateur a accès
        if (!$this->user_has_access()) {
            wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', 'claude-assistant'));
        }
        
        echo '<div class="wrap">';
        echo '<h1>Tableau de bord Claude Assistant</h1>';
        
        // Stats d'utilisation
        global $wpdb;
        $table_name = $wpdb->prefix . 'claude_conversations';
        
        $total_messages = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_conversations = $wpdb->get_var("SELECT COUNT(DISTINCT conversation_id) FROM $table_name");
        $audio_messages = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE message_type = 'audio'");
        
        echo '<div class="claude-stats-container">';
        echo '<div class="claude-stat-box">';
        echo '<h3>Total des messages</h3>';
        echo '<div class="stat-value">' . esc_html($total_messages) . '</div>';
        echo '</div>';
        
        echo '<div class="claude-stat-box">';
        echo '<h3>Conversations</h3>';
        echo '<div class="stat-value">' . esc_html($total_conversations) . '</div>';
        echo '</div>';
        
        echo '<div class="claude-stat-box">';
        echo '<h3>Messages audio</h3>';
        echo '<div class="stat-value">' . esc_html($audio_messages) . '</div>';
        echo '</div>';
        echo '</div>';
        
        // Graphiques (à intégrer avec Chart.js)
        echo '<div class="claude-chart-container">';
        echo '<div class="claude-chart" id="messages-chart">';
        echo '<h3>Messages par jour</h3>';
        echo '<canvas id="messagesPerDay"></canvas>';
        echo '</div>';
        echo '</div>';
        
             echo '</div>'; // fin de .wrap
    }
    
    /**
     * Charger les assets d'administration
     */
    public function enqueue_admin_assets($hook) {
        // Charger uniquement sur les pages de notre plugin
        if (strpos($hook, 'claude-assistant') === false) {
            return;
        }
        
        // CSS
        wp_enqueue_style(
            'claude-assistant-admin-css',
            $this->plugin_url . 'assets/css/admin.css',
            array(),
            $this->version
        );
        
        // JavaScript
        wp_enqueue_script(
            'claude-assistant-admin-js',
            $this->plugin_url . 'assets/js/admin.js',
            array('jquery'),
            $this->version,
            true
        );
        
        // Chart.js pour les graphiques du tableau de bord
        if ($hook === 'claude-assistant_page_claude-assistant-dashboard') {
            wp_enqueue_script(
                'chartjs',
                'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.0/chart.min.js',
                array(),
                '3.7.0',
                true
            );
            
            wp_enqueue_script(
                'claude-assistant-dashboard-js',
                $this->plugin_url . 'assets/js/dashboard.js',
                array('jquery', 'chartjs'),
                $this->version,
                true
            );
            
            // Préparer les données pour les graphiques
            global $wpdb;
            $table_name = $wpdb->prefix . 'claude_conversations';
            
            $messages_per_day = $wpdb->get_results(
                "SELECT DATE(created_at) as date, COUNT(*) as count
                FROM $table_name
                GROUP BY DATE(created_at)
                ORDER BY date DESC
                LIMIT 30"
            );
            
            wp_localize_script(
                'claude-assistant-dashboard-js',
                'claudeAssistantData',
                array(
                    'messagesPerDay' => $messages_per_day
                )
            );
        }
        
        // Localisation pour le script
        wp_localize_script(
            'claude-assistant-admin-js',
            'claudeAssistant',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => rest_url('claude-assistant/v1/'),
                'nonce' => wp_create_nonce('wp_rest'),
                'webhook_url' => get_option('n8n_webhook_url', '')
            )
        );
    }
    
    /**
     * Charger les assets pour le frontend
     */
    public function enqueue_frontend_assets() {
        // Ne charger que si l'utilisateur a accès
        if (!$this->user_has_access()) {
            return;
        }
        
        // CSS
        wp_enqueue_style(
            'claude-assistant-css',
            $this->plugin_url . 'assets/css/frontend.css',
            array(),
            $this->version
        );
        
        // JavaScript
        wp_enqueue_script(
            'claude-assistant-js',
            $this->plugin_url . 'assets/js/frontend.js',
            array('jquery'),
            $this->version,
            true
        );
        
        // Localisation pour le script
        wp_localize_script(
            'claude-assistant-js',
            'claudeAssistant',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => rest_url('claude-assistant/v1/'),
                'nonce' => wp_create_nonce('wp_rest'),
                'webhook_url' => get_option('n8n_webhook_url', '')
            )
        );
    }
    
    /**
     * Vérifier si l'utilisateur a accès
     */
    public function user_has_access() {
        if (!is_user_logged_in()) {
            return false;
        }
        
        $allowed_roles = get_option('claude_assistant_access_roles', array('administrator'));
        $user = wp_get_current_user();
        
        foreach ($allowed_roles as $role) {
            if (in_array($role, (array) $user->roles)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obtenir le HTML de l'interface de chat
     */
    public function get_chat_interface_html() {
        ob_start();
        include($this->plugin_path . 'templates/chat-interface.php');
        return ob_get_clean();
    }
    
    /**
     * Afficher l'interface de chat (pour le shortcode)
     */
    public function display_chat_interface($atts) {
        // Vérifier si l'utilisateur a accès
        if (!$this->user_has_access()) {
            return '<p>' . __('Vous devez être connecté avec un rôle autorisé pour accéder à Claude Assistant.', 'claude-assistant') . '</p>';
        }
        
        // Paramètres du shortcode
        $atts = shortcode_atts(
            array(
                'height' => '600px',
                'width' => '100%',
                'show_new_chat' => 'true'
            ),
            $atts,
            'claude_chat'
        );
        
        // Variables pour le template
        $show_new_chat = ($atts['show_new_chat'] === 'true');
        $include_js = true;
        
        // Inclure le template
        ob_start();
        include($this->plugin_path . 'templates/chat-interface.php');
        return ob_get_clean();
    }
    
    /**
     * Enregistrer les routes REST API
     */
    public function register_rest_routes() {
        // Route pour envoyer un message texte
        register_rest_route('claude-assistant/v1', '/send-message', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_send_message'),
            'permission_callback' => array($this, 'check_rest_permission'),
        ));
        
        // Route pour envoyer un message audio
        register_rest_route('claude-assistant/v1', '/send-audio', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_send_audio'),
            'permission_callback' => array($this, 'check_rest_permission'),
        ));
        
        // Route pour envoyer une image
        register_rest_route('claude-assistant/v1', '/send-image', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_send_image'),
            'permission_callback' => array($this, 'check_rest_permission'),
        ));
        
        // Route pour recevoir un message de n8n
        register_rest_route('claude-assistant/v1', '/receive-message', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_receive_message'),
            'permission_callback' => '__return_true', // Accessible publiquement, mais protégé par un token
        ));
        
        // Route pour exporter les données
        register_rest_route('claude-assistant/v1', '/export-data', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_export_data'),
            'permission_callback' => array($this, 'check_rest_permission'),
        ));
    }
    
    /**
     * Vérifier les permissions pour l'API REST
     */
    public function check_rest_permission() {
        return $this->user_has_access();
    }
    
    /**
     * Gérer l'envoi d'un message texte
     */
    public function handle_send_message($request) {
        $params = $request->get_params();
        
        // Valider les paramètres
        if (!isset($params['message']) || empty($params['message'])) {
            return new WP_Error('missing_message', 'Le message est requis', array('status' => 400));
        }
        
        $message = sanitize_text_field($params['message']);
        $message_type = isset($params['type']) ? sanitize_text_field($params['type']) : 'text';
        $conversation_id = isset($params['conversation_id']) ? sanitize_text_field($params['conversation_id']) : $this->generate_conversation_id();
        
        // Envoyer le message à n8n ou directement à l'API Claude
        $api_key = get_option('claude_api_key', '');
        $n8n_url = get_option('n8n_webhook_url', '');
        
        if (!empty($n8n_url)) {
            // Utiliser n8n comme intermédiaire
            $response = wp_remote_post($n8n_url, array(
                'body' => json_encode(array(
                    'message' => $message,
                    'type' => $message_type,
                    'conversation_id' => $conversation_id,
                    'user_id' => get_current_user_id()
                )),
                'headers' => array('Content-Type' => 'application/json'),
                'timeout' => 45 // Timeout plus long pour les réponses de l'IA
            ));
            
            if (is_wp_error($response)) {
                return new WP_Error('n8n_error', $response->get_error_message(), array('status' => 500));
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!isset($body['response'])) {
                return new WP_Error('invalid_response', 'Réponse invalide de n8n', array('status' => 500));
            }
            
            $claude_response = $body['response'];
        } else if (!empty($api_key)) {
            // Appeler directement l'API Claude
            $claude_response = $this->call_claude_api($message, $conversation_id);
            
            if (is_wp_error($claude_response)) {
                return $claude_response;
            }
        } else {
            return new WP_Error('missing_configuration', 'Configuration manquante (Clé API ou Webhook URL)', array('status' => 500));
        }
        
        // Sauvegarder la conversation
        $this->save_conversation($conversation_id, $message, $claude_response, $message_type);
        
        return array(
            'success' => true,
            'response' => $claude_response,
            'conversation_id' => $conversation_id
        );
    }
    
    /**
     * Gérer l'envoi d'un message audio
     */
    public function handle_send_audio($request) {
        // Obtenir les paramètres de la requête
        $files = $request->get_file_params();
        $params = $request->get_params();
        
        if (empty($files['audio'])) {
            return new WP_Error('missing_audio', 'Fichier audio manquant', array('status' => 400));
        }
        
        $conversation_id = isset($params['conversation_id']) ? sanitize_text_field($params['conversation_id']) : $this->generate_conversation_id();
        
        // Sauvegarder le fichier temporairement
        $upload_dir = wp_upload_dir();
        $claude_dir = $upload_dir['basedir'] . '/claude-assistant';
        
        if (!file_exists($claude_dir)) {
            wp_mkdir_p($claude_dir);
        }
        
        $temp_file = $claude_dir . '/' . uniqid('audio_') . '.webm';
        move_uploaded_file($files['audio']['tmp_name'], $temp_file);
        
        // Transcription (via n8n ou un service de transcription)
        $n8n_url = get_option('n8n_webhook_url', '');
        
        if (!empty($n8n_url)) {
            // Envoyer à n8n pour transcription
            $audio_url = $upload_dir['baseurl'] . '/claude-assistant/' . basename($temp_file);
            
            $response = wp_remote_post($n8n_url, array(
                'body' => json_encode(array(
                    'type' => 'audio',
                    'audio_url' => $audio_url,
                    'conversation_id' => $conversation_id,
                    'user_id' => get_current_user_id()
                )),
                'headers' => array('Content-Type' => 'application/json'),
                'timeout' => 60
            ));
            
            if (is_wp_error($response)) {
                return new WP_Error('n8n_error', $response->get_error_message(), array('status' => 500));
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!isset($body['transcription']) || !isset($body['response'])) {
                return new WP_Error('invalid_response', 'Réponse invalide de n8n', array('status' => 500));
            }
            
            $transcription = $body['transcription'];
            $claude_response = $body['response'];
            
            // Sauvegarder la conversation
            $this->save_conversation($conversation_id, $transcription, $claude_response, 'audio');
            
            // Supprimer le fichier temporaire
            @unlink($temp_file);
            
            return array(
                'success' => true,
                'transcription' => $transcription,
                'response' => $claude_response,
                'conversation_id' => $conversation_id
            );
        } else {
            // Pas de service de transcription configuré
            @unlink($temp_file);
            return new WP_Error('missing_n8n', 'Service de transcription non configuré', array('status' => 500));
        }
    }
    
    /**
     * Gérer l'envoi d'une image
     */
    public function handle_send_image($request) {
        $params = $request->get_params();
        
        if (!isset($params['image']) || empty($params['image'])) {
            return new WP_Error('missing_image', 'Image manquante', array('status' => 400));
        }
        
        $image_data = $params['image'];
        $filename = isset($params['filename']) ? sanitize_text_field($params['filename']) : 'image.jpg';
        $conversation_id = isset($params['conversation_id']) ? sanitize_text_field($params['conversation_id']) : $this->generate_conversation_id();
        
        // Traiter l'image avec Claude via n8n
        $n8n_url = get_option('n8n_webhook_url', '');
        
        if (!empty($n8n_url)) {
            $response = wp_remote_post($n8n_url, array(
                'body' => json_encode(array(
                    'type' => 'image',
                    'image' => $image_data,
                    'filename' => $filename,
                    'conversation_id' => $conversation_id,
                    'user_id' => get_current_user_id()
                )),
                'headers' => array('Content-Type' => 'application/json'),
                'timeout' => 60
            ));
            
            if (is_wp_error($response)) {
                return new WP_Error('n8n_error', $response->get_error_message(), array('status' => 500));
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!isset($body['response'])) {
                return new WP_Error('invalid_response', 'Réponse invalide de n8n', array('status' => 500));
            }
            
            $claude_response = $body['response'];
            
            // Sauvegarder la conversation
            $this->save_conversation($conversation_id, "Image: $filename", $claude_response, 'image');
            
            return array(
                'success' => true,
                'response' => $claude_response,
                'conversation_id' => $conversation_id
            );
        } else {
            return new WP_Error('missing_n8n', 'Service de traitement d\'images non configuré', array('status' => 500));
        }
    }
    
    /**
     * Gérer la réception d'un message de n8n
     */
    public function handle_receive_message($request) {
        $params = $request->get_params();
        
        // Valider les paramètres
        if (!isset($params['response']) || empty($params['response'])) {
            return new WP_Error('missing_response', 'La réponse est requise', array('status' => 400));
        }
        
        if (!isset($params['message']) || empty($params['message'])) {
            return new WP_Error('missing_message', 'Le message original est requis', array('status' => 400));
        }
        
        if (!isset($params['user_id']) || empty($params['user_id'])) {
            return new WP_Error('missing_user_id', 'L\'ID utilisateur est requis', array('status' => 400));
        }
        
        $response = sanitize_text_field($params['response']);
        $message = sanitize_text_field($params['message']);
        $user_id = intval($params['user_id']);
        $message_type = isset($params['type']) ? sanitize_text_field($params['type']) : 'text';
        $conversation_id = isset($params['conversation_id']) ? sanitize_text_field($params['conversation_id']) : $this->generate_conversation_id();
        
        // Vérifier le token pour sécuriser l'endpoint (optionnel)
        // TODO: Implémenter la vérification de token entre n8n et WordPress
        
        // Sauvegarder la conversation
        $this->save_conversation($conversation_id, $message, $response, $message_type, $user_id);
        
        return array(
            'success' => true,
            'message' => 'Message reçu et enregistré'
        );
    }
    
    /**
     * Gérer l'exportation des données
     */
    public function handle_export_data($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'claude_conversations';
        $user_id = get_current_user_id();
        
        // Récupérer toutes les conversations de l'utilisateur
        $conversations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name
                WHERE user_id = %d
                ORDER BY conversation_id, created_at ASC",
                $user_id
            )
        );
        
        if (empty($conversations)) {
            return array(
                'success' => false,
                'message' => 'Aucune donnée à exporter'
            );
        }
        
        // Créer un fichier CSV
        $csv = "ID de conversation,Message,Réponse,Type,Date\n";
        
        foreach ($conversations as $conversation) {
            $csv .= '"' . esc_html($conversation->conversation_id) . '",';
            $csv .= '"' . str_replace('"', '""', $conversation->message) . '",';
            $csv .= '"' . str_replace('"', '""', $conversation->response) . '",';
            $csv .= '"' . esc_html($conversation->message_type) . '",';
            $csv .= '"' . esc_html($conversation->created_at) . '"';
            $csv .= "\n";
        }
        
        return array(
            'success' => true,
            'data' => $csv
        );
    }
    
    /**
     * Appeler l'API Claude directement
     */
    private function call_claude_api($message, $conversation_id) {
        $api_key = get_option('claude_api_key', '');
        $model = get_option('claude_model', 'claude-3-opus-20240229');
        $temperature = floatval(get_option('claude_temperature', '0.7'));
        
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', 'Clé API Claude manquante', array('status' => 500));
        }
        
        $headers = array(
            'Content-Type' => 'application/json',
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01'
        );
        
        $data = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $message
                )
            ),
            'temperature' => $temperature,
            'max_tokens' => 4000
        );
        
        // Ajouter l'ID de conversation comme métadonnée
        if (!empty($conversation_id)) {
            $data['metadata'] = array(
                'conversation_id' => $conversation_id
            );
        }
        
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => $headers,
            'body' => json_encode($data),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            $error_message = wp_remote_retrieve_body($response);
            return new WP_Error('claude_api_error', $error_message, array('status' => $status_code));
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['content']) || !isset($body['content'][0]['text'])) {
            return new WP_Error('invalid_response', 'Réponse invalide de Claude API', array('status' => 500));
        }
        
        return $body['content'][0]['text'];
    }
    
    /**
     * Générer un ID de conversation
     */
    private function generate_conversation_id() {
        return 'conv_' . wp_generate_uuid4();
    }
    
    /**
     * Sauvegarder une conversation
     */
    private function save_conversation($conversation_id, $message, $response, $message_type = 'text', $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'claude_conversations';
        
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'conversation_id' => $conversation_id,
                'message' => $message,
                'response' => $response,
                'message_type' => $message_type,
                'created_at' => current_time('mysql')
            )
        );
        
        return $wpdb->insert_id;
    }
}

// Initialiser le plugin
$claude_assistant = new Claude_Assistant();
