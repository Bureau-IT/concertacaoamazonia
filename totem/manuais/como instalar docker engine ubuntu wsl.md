<img src="https://r2cdn.perplexity.ai/pplx-full-logo-primary-dark%402x.png" style="height:64px;margin-right:32px"/>

# como instalar docker engine ubuntu wsl

Para instalar o Docker Engine no Ubuntu rodando dentro do WSL (Windows Subsystem for Linux), você deve seguir um processo praticamente idêntico à instalação do Docker numa distribuição Ubuntu normal. Abaixo está um passo a passo básico e funcional para isso:

1. Atualize os pacotes do seu Ubuntu WSL:
```
sudo apt update
sudo apt upgrade -y
```

2. Instale os pacotes necessários para o apt usar repositórios via HTTPS:
```
sudo apt install apt-transport-https ca-certificates curl software-properties-common -y
```

3. Adicione a chave GPG oficial do Docker:
```
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg
```

4. Adicione o repositório oficial do Docker para Ubuntu:
```
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
```

5. Atualize a lista de pacotes apt:
```
sudo apt update
```

6. Instale o Docker Engine:
```
sudo apt install docker-ce docker-ce-cli containerd.io -y
```

7. Adicione seu usuário ao grupo docker para poder executar comandos Docker sem sudo:
```
sudo usermod -aG docker $USER
```

Depois reinicie sua sessão do Ubuntu para aplicar.

8. Teste a instalação rodando um container simples:
```
docker run hello-world
```

Observação importante: para integração da experiência Docker no WSL com Windows, normalmente se recomenda usar o Docker Desktop que tem suporte nativo para WSL 2. Porém, se preferir instalar o Docker Engine diretamente na distribuição Ubuntu do WSL, este é o procedimento correto. Vale lembrar que, sem o Docker Desktop, o Docker Engine no WSL vai rodar isolado dentro do Linux, sem integração nativa com o Windows.

Esta instalação é válida para Ubuntu no WSL 2, seguindo o método padrão de instalação do Docker no Ubuntu Linux.[^1_2][^1_3][^1_6][^1_7]
<span style="display:none">[^1_1][^1_4][^1_5][^1_8][^1_9]</span>

---

# docker run hello-world

docker: permission denied while trying to connect to the Docker daemon socket at unix:///var/run/docker.sock: Head "http://%2Fvar%2Frun%2Fdocker.sock/_ping": dial unix /var/run/docker.sock: connect: permission denied

Run 'docker run --help' for more information

O erro "permission denied while trying to connect to the Docker daemon socket at unix:///var/run/docker.sock" indica que seu usuário atual não tem permissão para acessar o socket do Docker, que é necessário para comunicar-se com o daemon do Docker.

Para resolver isso, siga os passos abaixo:

1. Certifique-se de que você adicionou seu usuário ao grupo "docker" corretamente:
```
sudo usermod -aG docker $USER
```

2. Após rodar o comando acima, você precisa encerrar sua sessão e entrar novamente no Ubuntu WSL para que a mudança de grupos surta efeito. Apenas fechar o terminal não basta, precisa reiniciar a instância do WSL ou abrir uma nova sessão.
3. Verifique se o daemon do Docker está rodando:
```
sudo service docker start
```

ou

```
sudo systemctl start docker
```

Dependendo da configuração, pode ser que systemd não esteja habilitado no WSL; nesse caso, o comando "service" é preferível.

4. Depois de reiniciar a sessão e garantir que o Docker está ativo, tente rodar novamente:
```
docker run hello-world
```

Se mesmo assim der problema, tente rodar com sudo para confirmar se é uma questão de permissão:

```
sudo docker run hello-world
```

Resumo: seu usuário precisa estar no grupo docker e o daemon Docker precisa estar rodando para evitar "permission denied" ao acessar o socket do Docker. Reiniciar a sessão do WSL depois de adicionar o usuário ao grupo é fundamental para aplicar a permissão.