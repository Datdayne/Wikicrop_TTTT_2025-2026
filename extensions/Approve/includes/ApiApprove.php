<?php
namespace MediaWiki\Extension\Approve;

use MediaWiki\MediaWikiServices;
use MediaWiki\Api\ApiBase;
use MediaWiki\Revision\SlotRecord; // Cần dòng này để lấy nội dung bài viết

class ApiApprove extends ApiBase {

    public function execute() {
        // 1. Lấy tham số
        $params = $this->extractRequestParams();
        $id = (int)$params['id'];
        $mode = $params['mode'];

        // 2. Kiểm tra quyền Admin
        $user = $this->getUser();
        if ( !$user->isAllowed( 'approverevisions' ) ) {
            $this->dieWithError( [ 'apierror-permissiondenied' ] );
        }

        // 3. Kết nối DB
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

        // 4. Tìm bản ghi trong hàng đợi
        $row = $dbw->selectRow(
            'approve_queue',
            [ 'aq_id', 'aq_page_id', 'aq_revision_id', 'aq_page_title' ],
            [ 'aq_id' => $id ],
            __METHOD__
        );

        if ( !$row ) {
            $this->dieWithError( [ 'apierror-nosuchrevid' ], 'notfound' );
        }

        // 5. Xử lý logic
        if ( $mode === 'approve' ) {
            // --- A. Cập nhật trạng thái trong Database ---
            $dbw->update(
                'approve_queue',
                [
                    'aq_status' => 'approved',
                    'aq_approver' => $user->getName(),
                    'aq_approved_at' => $dbw->timestamp( wfTimestampNow() )
                ],
                [ 'aq_id' => $id ],
                __METHOD__
            );

            // Đánh dấu các bản cũ là không còn mới nhất
            $dbw->update(
                'approve_queue',
                [ 'aq_is_latest' => 0 ],
                [
                    'aq_page_id' => $row->aq_page_id,
                    $dbw->expr( 'aq_id', '!=', $id )
                ],
                __METHOD__
            );

            // --- B. GỬI DỮ LIỆU SANG CHATBOT (QUAN TRỌNG) ---
            // Gọi hàm riêng để code gọn gàng
            $this->sendToChatbot( $row->aq_revision_id, $row->aq_page_title );
            // ------------------------------------------------

            $this->getResult()->addValue( null, $this->getModuleName(), [ 'success' => true, 'action' => 'approved' ] );

        } elseif ( $mode === 'reject' ) {
            // Từ chối duyệt
            $dbw->update(
                'approve_queue',
                [ 'aq_status' => 'rejected', 'aq_is_latest' => 0 ],
                [ 'aq_id' => $id ],
                __METHOD__
            );
            $this->getResult()->addValue( null, $this->getModuleName(), [ 'success' => true, 'action' => 'rejected' ] );

        } else {
            $this->dieWithError( [ 'apierror-badmode' ], 'badmode' );
        }
    }

    /**
     * Hàm gửi nội dung bài viết sang Server Python để học lại (Ingest)
     */
private function sendToChatbot( $revId, $title ) {
        try {
            $revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
            $rev = $revisionStore->getRevisionById( $revId );

            if ( $rev ) {
                $content = $rev->getContent( SlotRecord::MAIN );
                $text = $content ? $content->getText() : '';

                if ( $text ) {
                    // --- FIX LỖI Ở ĐÂY ---
                    // 1. Đảm bảo UTF-8
                    $cleanTitle = mb_convert_encoding($title, 'UTF-8', 'UTF-8');
                    $cleanText = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

                    // 2. Tạo mảng dữ liệu
                    $data = [
                        'title' => $cleanTitle,
                        'content' => $cleanText,
                        'url' => "wiki://" . str_replace(' ', '_', $cleanTitle)
                    ];

                    // 3. Encode JSON với các cờ an toàn
                    // JSON_INVALID_UTF8_IGNORE: Bỏ qua ký tự lỗi thay vì trả về null
                    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE);

                    // Nếu encode thất bại (trả về false), dừng lại
                    if ($payload === false) {
                        return; 
                    }

                    $httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()->create(
                        'http://localhost:8000/ingest',
                        [
                            'method' => 'POST',
                            'postData' => $payload,
                            'headers' => [ 'Content-Type' => 'application/json' ]
                        ],
                        __METHOD__
                    );

                    $status = $httpRequest->execute();
                }
            }
        } catch ( \Exception $e ) {
            // Im lặng nếu lỗi để không chặn quy trình duyệt
        }
    }

    public function needsToken() {
        return 'csrf';
    }

    public function getAllowedParams() {
        return [
            'id' => [
                ApiBase::PARAM_REQUIRED => true,
                ApiBase::PARAM_TYPE => 'integer'
            ],
            'mode' => [
                ApiBase::PARAM_REQUIRED => true,
                ApiBase::PARAM_TYPE => ['approve', 'reject']
            ]
        ];
    }
}