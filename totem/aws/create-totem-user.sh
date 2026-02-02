#!/bin/bash
# Script para criar usuário IAM com acesso restrito a bucket específico

# Configurações
PROFILE="ConcertaçãoSP"
USERNAME="s3-totem"
POLICY_NAME="S3-Totem"
BUCKET_NAME="amazonia-backups"  # Altere para o nome do seu bucket

# Verificar se o profile existe
echo "Verificando profile AWS: $PROFILE"
if ! aws configure list --profile "$PROFILE" &> /dev/null; then
    echo "ERRO: Profile '$PROFILE' não encontrado"
    exit 1
fi

# Obter o Account ID da conta AWS atual
echo "Obtendo Account ID..."
ACCOUNT_ID=$(aws sts get-caller-identity \
    --profile "$PROFILE" \
    --query Account \
    --output text)

if [ -z "$ACCOUNT_ID" ]; then
    echo "ERRO: Não foi possível obter o Account ID"
    exit 1
fi

echo "Account ID: $ACCOUNT_ID"

# Criar o usuário
echo "Criando usuário IAM: $USERNAME"
aws iam create-user \
    --user-name "$USERNAME" \
    --profile "$PROFILE"

# Criar arquivo JSON da policy
echo "Criando policy customizada..."
cat > /tmp/s3-policy.json <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:ListBucket"
      ],
      "Resource": [
        "arn:aws:s3:::${BUCKET_NAME}",
        "arn:aws:s3:::${BUCKET_NAME}/*"
      ]
    }
  ]
}
EOF

# Criar a policy na AWS
POLICY_ARN=$(aws iam create-policy \
    --policy-name "$POLICY_NAME" \
    --policy-document file:///tmp/s3-policy.json \
    --profile "$PROFILE" \
    --query 'Policy.Arn' \
    --output text)

if [ -z "$POLICY_ARN" ]; then
    echo "ERRO: Falha ao criar policy"
    exit 1
fi

echo "Policy criada: $POLICY_ARN"

# Anexar a policy ao usuário
echo "Anexando policy ao usuário..."
aws iam attach-user-policy \
    --user-name "$USERNAME" \
    --policy-arn "$POLICY_ARN" \
    --profile "$PROFILE"

# Criar access key para o usuário
echo "Criando credenciais de acesso..."
aws iam create-access-key \
    --user-name "$USERNAME" \
    --profile "$PROFILE" \
    > credentials.json

# Exibir as credenciais
echo "========================================"
echo "CREDENCIAIS GERADAS:"
echo "========================================"
cat credentials.json | jq -r '.AccessKey | "Access Key ID: \(.AccessKeyId)\nSecret Access Key: \(.SecretAccessKey)"'
echo "========================================"
echo ""
echo "Bucket permitido: $BUCKET_NAME"
echo "Credenciais salvas em: credentials.json"

# Limpar arquivo temporário
rm -f /tmp/s3-policy.json
