<?php
/**
 * The queue's behaviour, written ONCE and run twice: on the in-memory model
 * (catalogQueue.test.php, always) and on a real MySQL 8.4
 * (catalogQueueMysql.test.php, when CF_TEST_MYSQL is set). The second run is
 * what makes the first worth trusting — the model is only as honest as the
 * scenarios both of them pass.
 *
 * $env: 'reset' => fn( bool $posts_too = true ), 'seed_posts' => fn( [[id, type, status], …] ), 'rows' => fn(): array
 */
function cf_queue_scenarios( array $env ): void {
    $clock = 1790000000.0;
    CashFlow_Catalog::$clock = function () use ( &$clock ) { return $clock; };
    $keys = function ( $rows ) {
        return array_map( function ( $r ) { return (int) $r['product_id'] . ':' . $r['reason']; }, (array) $rows );
    };
    // The first claimed row, or a row that exists nowhere — so a broken queue
    // prints ✖ and carries on, instead of dying on a TypeError mid-file.
    $first = function ( $rows ) {
        return ( is_array( $rows ) && isset( $rows[0] ) ) ? $rows[0] : [ 'id' => '0', 'product_id' => '0', 'reason' => 'save', 'attempts' => '0' ];
    };

    echo "── one waiting row per product and reason\n";
    $env['reset']();
    CashFlow_Catalog::enqueue( 7, 'save' );
    CashFlow_Catalog::enqueue( 7, 'save' );
    CashFlow_Catalog::enqueue( 7, 'asked' );
    ok( 'a duplicate save is absorbed by the database', CashFlow_Catalog::count_pending() === 2, (string) CashFlow_Catalog::count_pending() );
    ok( 'a bad reason or id is refused before any SQL', CashFlow_Catalog::enqueue( 7, 'bogus' ) === false && CashFlow_Catalog::enqueue( 0, 'save' ) === false );

    echo "── real saves are claimed before asked and resend [review-2]\n";
    $env['reset']();
    CashFlow_Catalog::enqueue( 1, 'asked' );
    CashFlow_Catalog::enqueue( 2, 'save' );
    CashFlow_Catalog::enqueue( 3, 'removed' );
    CashFlow_Catalog::enqueue( 4, 'resend' );
    CashFlow_Catalog::enqueue( 5, 'save' );
    ok( 'claim_any takes the real ones first, oldest first', $keys( CashFlow_Catalog::claim( 'tokA', 2, false ) ) === [ '2:save', '3:removed' ] );
    ok( 'claim_real never takes asked or resend', $keys( CashFlow_Catalog::claim( 'tokB', 10, true ) ) === [ '5:save' ] );
    ok( 'then the rest, in id order', $keys( CashFlow_Catalog::claim( 'tokC', 10, false ) ) === [ '1:asked', '4:resend' ] );
    ok( 'nothing is left to claim', CashFlow_Catalog::claim( 'tokD', 10, false ) === [] );

    echo "── a save made while a send is in flight is a NEW row, never lost [B5]\n";
    $env['reset']();
    CashFlow_Catalog::enqueue( 9, 'save' );
    $out = CashFlow_Catalog::claim( 'tok1', 25, true );
    CashFlow_Catalog::enqueue( 9, 'save' );                   // the admin saves again mid-send
    ok( 'the second save inserted its own row', count( $env['rows']() ) === 2 );
    CashFlow_Catalog::done_row( $first( $out ), 'tok1' );     // the send succeeded
    $left = $env['rows']();
    ok( 'deleting the sent row by id AND token leaves the new save', count( $left ) === 1 && null === $left[0]['token'] );
    CashFlow_Catalog::done_row( $first( $out ), 'tok-other' );
    ok( 'a row is only ever deleted with its own token', count( $env['rows']() ) === 1 );

    echo "── a failed row waits for the next run, and parks after 5 tries [NB7]\n";
    $env['reset']();
    CashFlow_Catalog::enqueue( 11, 'save' );
    for ( $try = 1; $try <= 4; $try++ ) {
        $r = CashFlow_Catalog::claim( "t$try", 25, false );
        ok( "try $try: claimed", count( (array) $r ) === 1 );
        ok( "try $try: failing it releases it", CashFlow_Catalog::fail_row( $first( $r ), "t$try" ) === 'released' );
        ok( "try $try: not claimable again in the same run", CashFlow_Catalog::claim( "again$try", 25, false ) === [] );
        $clock += 61;                                          // the next run
    }
    $r = CashFlow_Catalog::claim( 't5', 25, false );
    ok( 'the fifth try carries 4 earlier failures', (int) $first( $r )['attempts'] === 4 );
    ok( 'failing it parks it', CashFlow_Catalog::fail_row( $first( $r ), 't5' ) === 'parked' );
    ok( 'a parked row is not claimed', CashFlow_Catalog::claim( 't6', 25, false ) === [] );
    ok( 'it is counted as parked, not pending', CashFlow_Catalog::count_parked() === 1 && CashFlow_Catalog::count_pending() === 0 );
    ok( 'and listed by product', CashFlow_Catalog::parked_ids() === [ 11 ] );
    CashFlow_Catalog::enqueue( 11, 'save' );
    ok( 'a new save of a parked product queues afresh', CashFlow_Catalog::count_pending() === 1 );
    CashFlow_Catalog::clear_parked( 11 );
    ok( 'clear_parked removes only the parked row', CashFlow_Catalog::count_parked() === 0 && CashFlow_Catalog::count_pending() === 1 );

    echo "── a failed row whose product was saved again meanwhile gives way\n";
    $env['reset']();
    CashFlow_Catalog::enqueue( 12, 'save' );
    $r = CashFlow_Catalog::claim( 'tf', 25, false );
    CashFlow_Catalog::enqueue( 12, 'save' );
    ok( 'failing it is superseded by the newer waiting row', CashFlow_Catalog::fail_row( $first( $r ), 'tf' ) === 'superseded' );
    ok( 'exactly one row remains', count( $env['rows']() ) === 1 );
    $r = CashFlow_Catalog::claim( 'tu', 25, false );
    CashFlow_Catalog::release_row( $first( $r ), 'tu' );
    $again = CashFlow_Catalog::claim( 'tu2', 25, false );
    ok( 'an untried row is claimable again at once, no try counted', count( (array) $again ) === 1 && 0 === (int) $first( $again )['attempts'] );

    echo "── releasing an untried row whose product was saved again meanwhile also gives way\n";
    $env['reset']();
    CashFlow_Catalog::enqueue( 20, 'save' );
    $r = CashFlow_Catalog::claim( 'ts1', 25, false );
    CashFlow_Catalog::enqueue( 20, 'save' );                  // a newer save while the claim sits untried
    CashFlow_Catalog::release_row( $first( $r ), 'ts1' );
    $left = $env['rows']();
    ok( 'the stale claim is discarded, the newer save kept', count( $left ) === 1 && null === $left[0]['token'] );
    ok( 'and it carries no attempt for the discarded claim', 0 === (int) $left[0]['attempts'] );

    echo "── a claim held by a run that died is taken back after the lease\n";
    $env['reset']();
    CashFlow_Catalog::enqueue( 13, 'save' );
    CashFlow_Catalog::claim( 'dead', 25, false );             // the run that claimed it died (a fatal)
    ok( 'within the lease it stays with the dead run', CashFlow_Catalog::claim( 'live', 25, false ) === [] );
    $clock += 301;
    $back = CashFlow_Catalog::claim( 'live', 25, false );
    ok( 'after the lease it is taken back', count( (array) $back ) === 1 && 13 === (int) $first( $back )['product_id'] );
    ok( 'and the dead run counts as one try', 1 === (int) $first( $back )['attempts'] );

    echo "── the whole set is queued for a resend in ONE statement [S9]\n";
    $env['reset']();
    $env['seed_posts']( [
        [ 10, 'product', 'publish' ], [ 11, 'product', 'trash' ], [ 12, 'product_variation', 'publish' ],
        [ 13, 'product', 'draft' ], [ 14, 'product', 'private' ], [ 15, 'product', 'auto-draft' ], [ 16, 'post', 'publish' ],
    ] );
    ok( 'queueing the set succeeds', CashFlow_Catalog::queue_whole_set_for_resend() === true );
    $got = $keys( CashFlow_Catalog::claim( 'tr', 100, false ) );
    sort( $got );
    ok( 'exactly the set: products in publish/future/draft/pending/private', $got === [ '10:resend', '13:resend', '14:resend' ], implode( ',', $got ) );
    $env['reset']( false );                                    // keep the posts
    CashFlow_Catalog::queue_whole_set_for_resend();
    CashFlow_Catalog::queue_whole_set_for_resend();
    ok( 'running it twice adds nothing', CashFlow_Catalog::count_pending() === 3 );

    echo "── the set is enumerated in ascending id, after a position [NB3]\n";
    ok( 'from 0', CashFlow_Catalog::enumerate( 0, 2 ) === [ 10, 13 ] );
    ok( 'after 10', CashFlow_Catalog::enumerate( 10, 10 ) === [ 13, 14 ] );
    ok( 'past the end: an empty list, which is NOT an error', CashFlow_Catalog::enumerate( 14, 10 ) === [] );

    echo "── claim_listed() takes exactly the listed ids, ANY reason, one at a time, oldest first [CRITICAL, review-4]\n";
    $env['reset']();
    CashFlow_Catalog::enqueue( 1, 'asked' );      // listed, and the oldest of the listed rows
    CashFlow_Catalog::enqueue( 2, 'save' );        // NOT listed — must never be taken instead
    CashFlow_Catalog::enqueue( 3, 'resend' );      // listed
    ok( 'the first call takes the oldest LISTED row, not the oldest row overall', $keys( CashFlow_Catalog::claim_listed( 'clA', [ 1, 3 ] ) ) === [ '1:asked' ] );
    ok( 'the second call takes the next listed row', $keys( CashFlow_Catalog::claim_listed( 'clB', [ 1, 3 ] ) ) === [ '3:resend' ] );
    ok( 'nothing listed is left; the unlisted save was never touched', CashFlow_Catalog::claim_listed( 'clC', [ 1, 3 ] ) === [] );
    ok( 'and it never claimed more than one row even when asked for many ids', count( $env['rows']() ) === 3 && [] === array_filter( $env['rows'](), function ( $r ) { return '0' !== $r['attempts']; } ) );

    echo "── still_queued() answers which listed ids still have a row, without claiming or mutating anything\n";
    $env['reset']();
    CashFlow_Catalog::enqueue( 5, 'save' );
    CashFlow_Catalog::enqueue( 6, 'asked' );
    $before = $env['rows']();
    $present = CashFlow_Catalog::still_queued( [ 5, 6, 7 ] );
    sort( $present );
    ok( 'exactly the ids that have a row, id 7 never existed', $present === [ 5, 6 ] );
    ok( 'nothing was claimed or changed by asking', $env['rows']() === $before );

    echo "── still_queued() ignores a PARKED row — a parked id must be free to leave the solo list [review-5]\n";
    $env['reset']();
    CashFlow_Catalog::enqueue( 8, 'save' );
    CashFlow_Catalog::park_row( $first( CashFlow_Catalog::claim( 'pk1', 25, true ) ), 'pk1', 5 );
    ok( 'a parked row still exists, but is never "still queued"', CashFlow_Catalog::still_queued( [ 8 ] ) === [] );
    ok( 'an unparked sibling row for the same id is still reported', ( function () {
        CashFlow_Catalog::enqueue( 8, 'save' );
        return CashFlow_Catalog::still_queued( [ 8 ] ) === [ 8 ];
    } )() );

    CashFlow_Catalog::$clock = null;
}
