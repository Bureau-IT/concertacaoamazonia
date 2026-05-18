<?php
// PT content for BIT 4 Amazonias widget
if ( ! defined( "ABSPATH" ) ) exit;

$pt_content = [
    'label_premissa'      => 'Premissa básica',
    'label_especificas'   => 'Frentes de ações específicas',
    'label_estruturantes' => 'Frentes de ações estruturantes',
    'label_transversais'  => 'Frentes de ações transversais',
    'label_para_todas'    => 'Para todas as 4 Amazônias',
    'atua_em_todas'       => 'Atua em todas as 4 amazônias',
    'de_total'            => ' de ',

    'cols' => [
        [ 'id' => 'conservadas', 'label' => 'ÁREAS CONSERVADAS' ],
        [ 'id' => 'transicao', 'label' => 'ÁREAS DE TRANSIÇÃO' ],
        [ 'id' => 'convertidas', 'label' => 'ÁREAS CONVERTIDAS' ],
        [ 'id' => 'cidades', 'label' => 'CIDADES' ],
    ],

    'premissa' => [
        [ 'A floresta intacta permanece intacta' ],
        [ 'Contenção do desmatamento e regeneração da floresta e da biodiversidade' ],
        [ 'Rastreabilidade total assegura que externalidades negativas sejam minimizadas' ],
        [ 'Aumento da produtividade das atividades econômicas urbanas', 'Sustentabilidade urbana (saneamento, circularidade, mobilidade etc.)', 'Aplicação às cidades e sua infraestrutura das Soluções Baseadas na Natureza' ],
    ],

    'especificas' => [
        [ 'title' => '1 - ECONOMIA', 'cells' => [
            [ 'Conservação florestal', 'Sociobioeconomia', 'Aceleradoras de pequenos negócios da sociobioeconomia, com uso de tecnologias', 'Turismo', 'Economia criativa', 'Economia solidária' ],
            [ 'Restauração florestal, soluções baseadas na natureza, integração com manejo sustentável, SAF, ILPF, sociobioeconomia', 'Concessões florestais', 'Sivicultures de natas', 'Turismo baseado na natureza', 'Fortalecimento de cadeias agroalimentares já existentes' ],
            [ 'Produção de commodities agrominerais', 'Commodities com baixas externalidades inseridas em mosaicos de uso do solo que contemplem áreas de preservação e de produção', 'Corredores ecológicos para manutenção de biodiversidade', 'Restauração de ecossistemas', 'Concessões florestais', 'Máximo aproveitamento de produtos locais' ],
            [ 'Serviços', 'Indústrias', 'Economia criativa', 'Economia solidária', 'Empreendedorismo', 'Parcerias Público-Privadas (infraestrutura urbana, ex. saneamento básico)' ],
        ] ],
        [ 'title' => '2 - INSTRUMENTOS FISCAIS E MECANISMOS FINANCEIROS', 'cells' => [
            [ 'Mecanismos financeiros voltados para a conservação, não limitados a carbono', 'Projetos de REDD+' ],
            [ 'PSA, inclusive em adição ao Código Florestal (CF)', 'Incentivos fiscais para a bioeconomia de baixo impacto' ],
            [ 'PSA (externalidades positivas)', 'Programas de incentivo à agricultura sustentável (ex.: Programa ABC)', 'Incentivos fiscais e financeiros com contrapartidas para conservação', 'Eliminação de financiamento a atividades não sustentáveis e, ao longo do tempo, não rastreadas' ],
            [ 'Incentivos fiscais que relacionem indústria e serviços com atividades de conservação e distribuam benefícios para outras áreas da Amazônia', 'Incentivos para a economia circular (ex: resíduos sólidos)', 'Iniciativas urbanas inspiradas nas Soluções', 'Baseadas na Natureza' ],
        ] ],
        [ 'title' => '3 - ORDENAMENTO TERRITORIAL E REGULARIZAÇÃO FUNDIÁRIA', 'cells' => [
            [ 'Arrecadação de terras públicas e registro no sistema de administração de terras', 'Destinação de florestas públicas', 'Consolidação e ampliação de Unidades de Conservação e terras indígenas', 'Reconhecimento de direitos coletivos de outras comunidades tradicionais' ],
            [ 'Arrecadação de terras públicas e registro no sistema de administração de terras', 'Destinação de florestas públicas', 'Unidades de Conservação e concessões públicas', 'Ênfase na administração de terras com regularização fundiária de direitos individuais e coletivos e resolução de conflitos' ],
            [ 'Ênfase na administração de terras com regularização fundiária de direitos individuais e coletivos e resolução de conflitos', 'Cumprimento integral do CF' ],
            [ 'Revisão dos Planos Diretores Amazônicos', 'Incentivo à reocupação e restauração de imóveis desocupados e/ou deteriorados para alcançar os objetivos de cidades compactas e conectadas', 'Regularização fundiária urbana' ],
        ] ],
        [ 'title' => '4 - CIÊNCIA, TECNOLOGIA E INOVAÇÃO', 'cells' => [
            [ 'Aprofundamento do conhecimento da natureza e das culturas locais', 'Tecnologias sociais', 'Inclusão das populações locais, principalmente jovens' ],
            [ 'Silvicultura de nativas', 'Sustentabilidade de manejo', 'Ciência aplicada à bioeconomia de baixo impacto' ],
            [ 'Fortalecimento dos sistemas de rastreabilidade (blockchain, sensoriamento remoto, etc.)', 'Desenvolvimento de tecnologias mais adequadas à integração commodities - floresta', 'Sistemas alimentares compatíveis com a manutenção de biodiversidade', 'Tecnologias e metodologias para mensuração de carbono em atividades relacionadas ao uso do solo e monitoramento da biodiversidade' ],
            [ 'Resíduos sólidos', 'Economia circular', 'Economia criativa', 'Energia', 'Representatividade de pesquisadores locais', 'Expansão descentralizada de centros de pesquisa e inovação' ],
        ] ],
        [ 'title' => '5 - INFRAESTRUTURA', 'cells' => [
            [ 'Logística básica, de baixíssimo impacto, para acesso a locais remotos, porém alta tecnologia para atender demandas a partir de concepções modernizadas, a exemplo do transporte fluvial' ],
            [ 'Logística básica, de baixíssimo impacto, para acesso a locais remotos, porém alta tecnologia para atender demandas a partir de concepções modernizadas, a exemplo do transporte fluvial' ],
            [ 'Logística limpa, portuária, rodoviária, etc.' ],
            [ 'Infraestrutura verde associada à agenda de habitação (água, saneamento, mobilidade, parques, anéis de restauração, etc.) e com base em new building materials (baixo carbono, eficiência energética, etc.), de forma a aumentar o conforto dos moradores e reduzir o uso de produtos nocivos.', 'Infra para cuidado, cultura e bem-estar', 'Ações de adaptação às mudanças climáticas', 'Integração dos sistemas de energia' ],
        ] ],
        [ 'title' => '6 - QUESTÕES INDÍGENAS', 'cells' => [
            [ 'Proteção integral das Terras Indígenas, de sua sociodiversidade e biodiversidade' ],
            [ 'Proteção integral das Terras Indígenas, de sua sociodiversidade e biodiversidade' ],
            [ 'Recuperação dos ecossistemas das Terras Indigenas que foram modificados ou invadidos' ],
            [ 'Elaboração de programas para a proteção das pessoas indígenas que residem nas cidades amazônicas, em especial de geração de renda e de proteção dos seus direitos' ],
        ] ],
    ],

    'estruturantes' => [ 'Ações de comando & controle do desmatamento e degradação ambiental (rule of law), incluindo combate a grilagem de terras e aplicação integral do Código Florestal Brasileiro', 'Engajamento das populações locais na proteção dos recursos naturais, inclusive por meio de educação ambiental', 'Fortalecimento da Formação Geral Básica e Educação profissionalizante, bolsas de estudos', 'Acesso à saúde de qualidade (fortalecimento do sistema público de saúde)', 'Segurança pública para todos', 'Combate a ilegalidades e ilicitudes', 'Adaptação ás mudanças climáticas' ],
    'transversais'  => [ 'Fortalecimento da governança na Amazônia', 'Capacidades institucionais locais e do papel dos municípios', 'Entidades subnacionais', 'Interações com as instâncias de governança internacionais', 'Valorização da cultura: Amazônia como patrimônio de altíssimo valor para a sociedade como um todo' ],
];
