<?php
namespace MediaWiki\Extension\Approve;

use MediaWiki\MediaWikiServices;
use MediaWiki\Html\Html;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\User;

class ApproveHooks {

    /**
     * 1. Tạo bảng khi cài extension
     */
    public static function onSchemaUpdate( $updater ) {
        $dir = __DIR__ . '/../sql';
        $updater->addExtensionTable(
            'approve_queue',
            "$dir/mysql/tables.sql" // Đảm bảo đường dẫn này đúng với cấu trúc thư mục của bạn
        );
        return true;
    }

    /**
     * 2. Hook khi lưu bài (RevisionRecordInserted)
     */
    public static function onRevisionRecordInserted( RevisionRecord $revisionRecord ) {

        $pageId = $revisionRecord->getPageId();
        $revId  = $revisionRecord->getId();

        // --- CÁCH LẤY USER CHUẨN ---
        $user = $revisionRecord->getUser();
        
        // Lấy tên người sửa (Nếu không có user thì là IP hoặc Unknown)
        $creatorName = $user ? $user->getName() : 'Unknown';
        
        // Kiểm tra xem User này có quyền 'approverevisions' không?
        // Chúng ta cần convert UserIdentity sang User object để check quyền
        $userObj = User::newFromIdentity( $user );

        if ( $userObj && $userObj->isAllowed('approverevisions') ) {
            // Admin sửa bài -> Không cần duyệt -> Thoát
            return true; 
        }

        // Lấy Title
        $titleObj = $revisionRecord->getPageAsLinkTarget();
        $titleText = $titleObj->getText(); // Lấy tên bài viết

        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

        // Reset các bản ghi cũ của trang này (không còn là mới nhất nữa)
        $dbw->update(
            'approve_queue',
            [ 'aq_is_latest' => 0 ],
            [ 'aq_page_id' => $pageId ],
            __METHOD__
        );

        // Thêm bản ghi mới vào hàng đợi (Pending)
        $dbw->insert(
            'approve_queue',
            [
                'aq_page_id'     => $pageId,
                'aq_revision_id' => $revId,
                'aq_page_title'  => $titleText,
                'aq_creator'     => $creatorName,
                'aq_status'      => 'pending',
                'aq_is_latest'   => 1,
                'aq_created_at'  => $dbw->timestamp( wfTimestampNow() ) // Lưu thời gian tạo
            ],
            __METHOD__
        );

        return true;
    }

    /**
     * 3. Hiển thị thông báo "Đã duyệt" trên đầu bài viết
     */
    public static function onBeforePageDisplay( $out, $skin ) {
        $user = $out->getUser();

        // Chỉ Admin mới thấy công cụ duyệt (Load JS/CSS)
        if ( $user && $user->isAllowed('approverevisions') ) {
            $out->addModules('ext.approve');
        }

        // Lấy ID trang hiện tại
        $title = $out->getTitle();
        $pageId = $title->getArticleID();
        
        if ( !$pageId ) return true; // Trang đặc biệt hoặc chưa lưu -> Bỏ qua

        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );

        // Kiểm tra xem bản mới nhất của trang này đã được duyệt chưa
        $row = $dbr->selectRow(
            'approve_queue',
            ['aq_status', 'aq_approver', 'aq_approved_at'],
            [
                'aq_page_id' => $pageId,
                'aq_is_latest' => 1
            ],
            __METHOD__
        );

        if ( $row ) {
            if ( $row->aq_status === 'approved' ) {
                // Hiển thị thông báo XANH (Đã duyệt)
                $approver = htmlspecialchars($row->aq_approver);
                $time = $row->aq_approved_at;
                
                $html = "<div style='padding:10px; background:#e6ffea; border:1px solid #2ecc71; margin-bottom:15px; color:#155724; border-radius: 4px;'>
                            ✔ Phiên bản hiện tại đã được duyệt bởi <b>{$approver}</b>.
                         </div>";
                $out->prependHTML( $html );
            } elseif ( $row->aq_status === 'pending' ) {
                // Hiển thị thông báo VÀNG (Chờ duyệt) - Chỉ Admin hoặc tác giả thấy thì hay hơn, nhưng demo thì cứ hiện hết
                $html = "<div style='padding:10px; background:#fff3cd; border:1px solid #ffeeba; margin-bottom:15px; color:#856404; border-radius: 4px;'>
                            ⏳ Phiên bản này đang chờ ban quản trị duyệt.
                         </div>";
                $out->prependHTML( $html );
            } elseif ( $row->aq_status === 'rejected' ) {
                 // Hiển thị thông báo ĐỎ (Từ chối)
                 $html = "<div style='padding:10px; background:#f8d7da; border:1px solid #f5c6cb; margin-bottom:15px; color:#721c24; border-radius: 4px;'>
                            ✖ Phiên bản này đã bị từ chối duyệt.
                          </div>";
                 $out->prependHTML( $html );
            }
        }

        return true;
    }
}