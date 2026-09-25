<?php
/**
 * Checagem de compatibilidade da busca cross-blog com o JetSearch, contra classes
 * que simulam updates. Uso (dev):
 *   docker cp scripts/busca-crossblog-compat-fixtures.php concertacao-dev-wordpress:/tmp/
 *   docker exec -u www-data concertacao-dev-wordpress wp eval-file /tmp/busca-crossblog-compat-fixtures.php
 * Saída esperada: "TODAS AS FIXTURES OK" e a classe real do JetSearch "[]".
 * Complementa testes/tests/13-busca-crossblog.spec.js, que testa o site vivo.
 */
// Fixtures que simulam updates do JetSearch. Cada uma é a classe base atual
// com UMA mudança. Esperado: '' só para a atual; motivo para as outras.
$base_body = '
	protected $source_name = null; protected $args = array(); protected $search_string = null;
	protected $items_list = array(); protected $results_count = 0; protected $limit = 5;
	public function get_name() { return $this->source_name; }
	public function render() { return ""; }
	abstract public function get_label(); abstract public function get_priority();
	abstract public function build_items_list(); abstract public function get_query_result();
';
$cases = [
	'Atual'              => [ $base_body, true ],
	'AbstratoNovo'       => [ $base_body . 'abstract public function get_icon();', false ],
	'RenderComRetorno'   => [ str_replace( 'public function render() {', 'public function render(): string {', $base_body ), false ],
	'QueryDoisParams'    => [ str_replace( 'abstract public function get_query_result();', 'abstract public function get_query_result( $limit = null, $offset = 0 );', $base_body ), false ],
	'SourceNameTipado'   => [ str_replace( 'protected $source_name = null;', 'protected ?string $source_name = null;', $base_body ), false ],
	'ColisaoTitles'      => [ $base_body . 'public function titles() { return []; }', false ],
	'SemItemsList'       => [ str_replace( 'protected $items_list = array();', '', $base_body ), false ],
	'RenderFinal'        => [ str_replace( 'public function render() {', 'final public function render() {', $base_body ), false ],
	'ConstrutorComArg'   => [ $base_body . 'public function __construct( $manager ) {}', false ],
	'SemGetName'         => [ str_replace( 'public function get_name() { return $this->source_name; }', '', $base_body ), false ],
];
$ok = true;
foreach ( $cases as $name => [ $body, $expect_ok ] ) {
	$class = 'BitFixture' . $name;
	eval( "abstract class $class { $body }" );
	$reason = bit_crossblog_search_check_base_class( $class );
	$pass   = $expect_ok ? '' === $reason : '' !== $reason;
	$ok     = $ok && $pass;
	printf( "%-4s %-18s %s\n", $pass ? 'OK' : 'FALHA', $name, '' === $reason ? '(compatível)' : $reason );
}
printf( "classe real do JetSearch: [%s]\n", bit_crossblog_search_jetsearch_incompat() );
echo $ok ? "TODAS AS FIXTURES OK\n" : "HÁ FALHAS\n";
