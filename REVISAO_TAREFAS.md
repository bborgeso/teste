# Revisão das tarefas (revalidação)

Este documento reavalia as 4 tarefas originalmente propostas e verifica se cada uma foi de fato ajustada no estado atual da base.

## Resultado rápido

- ✅ **Tarefa 1 (erro de digitação em HTML): ajustada**.
- ✅ **Tarefa 2 (bug sem `mbstring`): ajustada**.
- ⚠️ **Tarefa 3 (comentário/documentação vs comportamento): parcialmente ajustada / pendente de alinhamento definitivo**.
- ❌ **Tarefa 4 (melhoria de testes): não ajustada**.

---

## 1) Erro de digitação (HTML)

**Status:** ✅ Ajustada.

**Validação:**
- O arquivo `index.html` que continha o ponto de erro foi removido do repositório.
- Com isso, a inconsistência de atributo duplicado deixou de existir na base atual.

---

## 2) Bug de robustez sem `mbstring`

**Status:** ✅ Ajustada.

**Validação:**
- A normalização de cabeçalhos do CSV passou a usar `strtolower_safe()` no `parse_csv()`.
- O helper `strtolower_safe()` aplica fallback para `strtolower()` quando `mb_strtolower()` não está disponível.
- Isso elimina o risco de erro fatal em ambientes sem `mbstring`.

---

## 3) Alinhamento comentário/documentação vs comportamento real

**Status:** ⚠️ Parcial / pendente.

**Validação:**
- A UI informa: *se o e-mail já existir, os dados serão atualizados*.
- No `save_post()`, a deduplicação ainda segue padrão **primeira ocorrência vence** (`if (isset($seen[$key])) continue;`).
- No fluxo normal da interface (JS), a mesclagem já tende a manter uma única ocorrência por e-mail com atualização, então o comportamento final costuma funcionar como “atualiza”.
- Porém, no backend, a regra explícita permanece diferente da promessa textual caso cheguem dados duplicados ao salvamento.

**Ajuste recomendado para concluir a tarefa:**
- Escolher e padronizar uma única regra em todos os pontos (UI + backend):
  - ou alterar o texto para “primeira ocorrência vence”;
  - ou alterar o `save_post()` para “última ocorrência vence”.

---

## 4) Melhoria de testes

**Status:** ❌ Não ajustada.

**Validação:**
- Não foram encontrados testes automatizados cobrindo os cenários críticos sugeridos:
  - detecção de delimitador,
  - CSV com/sem cabeçalho,
  - deduplicação por e-mail,
  - busca por CPF com/sem pontuação,
  - fallback sem `mbstring`.

**Próximo passo recomendado:**
- Criar suite mínima de testes unitários para esses cenários e incluir ao menos regressão para os itens 2 e 3.
