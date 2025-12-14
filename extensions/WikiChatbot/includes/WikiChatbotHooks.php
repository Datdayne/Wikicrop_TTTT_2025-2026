<?php
/* File: extensions/WikiChatbot/includes/WikiChatbotHooks.php */

use MediaWiki\MediaWikiServices; // Import services để lấy config

class WikiChatbotHooks {
    public static function onBeforePageDisplay( OutputPage $out, Skin $skin ) {
        
        // Lấy cấu hình chính của MediaWiki
        $config = MediaWikiServices::getInstance()->getMainConfig();
        
        // Kiểm tra xem biến 'WikiChatbotEnabled' có tồn tại và bằng true không
        // Nếu không (hoặc bằng false), hàm sẽ dừng ngay lập tức -> Không hiện Chatbot
        if ( !$config->has( 'WikiChatbotEnabled' ) || !$config->get( 'WikiChatbotEnabled' ) ) {
            return;
        }
        // ----------------------------------

        // 2. Nạp file CSS và JS đã tách ở trên thông qua ResourceLoader
        $out->addModules( 'ext.wikichatbot' );

        // 3. Định nghĩa HTML (Phần giao diện)
        $chatbotHTML = <<<'HTML'
        <div id="chatbot-wrapper">
            <button id="chatbot-toggler" type="button">💬</button>
            <div id="chatbot-container">
                <div class="chat-header">
                    <span>Trợ lý Cây Trồng (AI) 🌱</span>
                    <span id="close-chat" style="cursor:pointer;">✖</span>
                </div>
                <div id="chat-messages">
                    <div class="bot-msg">Chào bạn! Tôi có thể giúp gì về kỹ thuật trồng cây?</div>
                </div>
                <div class="chat-input-area">
                    <input type="text" id="chat-input" placeholder="Nhập câu hỏi...">
                    <button id="send-btn" type="button">Gửi</button>
                </div>
            </div>
        </div>
HTML;

        // 4. Chèn HTML vào cuối trang
        $out->addHTML( $chatbotHTML );
    }
}