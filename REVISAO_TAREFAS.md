# Revisão rápida da base e tarefas sugeridas

## 1) Tarefa de correção de erro de digitação (HTML)
**Problema encontrado:** a tag de stylesheet em `index.html` tem o atributo `rel` repetido, o que indica erro de digitação/edição (`... type="text/css"` e novamente `rel="stylesheet"`).

**Onde:** `index.html` (head, linha do Bootstrap).

**Tarefa sugerida:**
- Corrigir a tag `<link>` removendo o atributo duplicado e padronizando a ordem dos atributos.

**Critério de aceite:**
- O arquivo fica com apenas um `rel="stylesheet"` por tag `<link>`.
- O HTML passa em um validador sem apontar atributo duplicado nessa linha.

---

## 2) Tarefa de correção de bug (robustez em ambiente sem mbstring)
**Problema encontrado:** `mb_strtolower()` é chamado sem verificar se a extensão `mbstring` está disponível.

**Onde:** `csv-json-hidden-datatable.php`, normalização de cabeçalho no `parse_csv()`.

**Risco:** em instalações WordPress sem `mbstring`, o upload/parsing de CSV pode gerar erro fatal.

**Tarefa sugerida:**
- Substituir o uso direto de `mb_strtolower()` por fallback seguro (`mb_strtolower` quando existir, senão `strtolower`).

**Critério de aceite:**
- O parsing continua funcionando com e sem `mbstring` habilitado.
- Não ocorre erro fatal ao importar CSV em ambiente sem `mbstring`.

---

## 3) Tarefa de ajuste de comentário/documentação (alinhar comportamento real)
**Problema encontrado:** o texto da UI diz que, se o e-mail já existir, os dados serão atualizados, mas no `save_post()` a deduplicação mantém a primeira ocorrência e ignora as seguintes.

**Onde:**
- Texto da interface no metabox: `render_metabox()`.
- Regra de deduplicação no `save_post()`.

**Tarefa sugerida (escolher uma abordagem):**
1. **Ajustar documentação/comentário** para refletir claramente que, no salvamento, a primeira ocorrência vence; **ou**
2. **Ajustar código** para “última ocorrência vence” e manter coerência com a promessa de atualização.

**Critério de aceite:**
- Texto exibido ao usuário e comportamento final no banco ficam consistentes.
- Caso o código seja alterado, incluir teste cobrindo duplicidade por e-mail.

---

## 4) Tarefa para melhorar teste
**Problema encontrado:** não há cobertura automatizada para cenários críticos de parsing e busca.

**Tarefa sugerida:**
- Criar testes unitários para:
  - detecção de delimitador (`,`, `;`, `\t`),
  - CSV com/sem cabeçalho,
  - deduplicação por e-mail,
  - busca por CPF com e sem pontuação,
  - comportamento quando `mbstring` não está disponível.

**Critério de aceite:**
- Suite de testes automatizada executa localmente e valida os cenários acima.
- Pelo menos um teste de regressão para cada bug/documentação ajustada nos itens 2 e 3.
