# Psilocybin Research Tracker: bibliometrics + visNetwork citation map
#
# This script downloads the current public SQLite database from
# https://psilocybin-research.com/database.php, runs local bibliometric
# summaries, and writes an interactive HTML citation network plus optional
# PDF/PNG snapshots.
#
# Usage:
#   Rscript psilocybin_bibliometrics_visnetwork.R
#
# Optional environment variables:
#   PSILO_DB_URL         Database URL. Default: https://psilocybin-research.com/database.php
#   PSILO_OUTPUT_DIR     Output folder. Default: psilocybin_bibliometrics_output
#   PSILO_MAX_PAPERS     Max seed papers for the citation network. Default: 300
#   PSILO_FROM_YEAR      Optional minimum publication year, e.g. 2020
#   PSILO_STATUS         Optional comma list: published,preprint,clinical trial,protocol,review
#   PSILO_TOPIC          Optional topic substring filter, e.g. depression
#   PSILO_SUBSTANCE      Optional substance substring filter, e.g. psilocybin

options(stringsAsFactors = FALSE, repos = c(CRAN = "https://cloud.r-project.org"))

if (!requireNamespace("pacman", quietly = TRUE)) {
  install.packages("pacman")
}

pacman::p_load(
  DBI,
  RSQLite,
  dplyr,
  tidyr,
  stringr,
  lubridate,
  jsonlite,
  purrr,
  readr,
  ggplot2,
  igraph,
  visNetwork,
  htmlwidgets,
  htmltools,
  DT,
  webshot2
)

cfg <- list(
  db_url = Sys.getenv("PSILO_DB_URL", "https://psilocybin-research.com/database.php"),
  output_dir = Sys.getenv("PSILO_OUTPUT_DIR", "psilocybin_bibliometrics_output"),
  max_papers = as.integer(Sys.getenv("PSILO_MAX_PAPERS", "300")),
  from_year = Sys.getenv("PSILO_FROM_YEAR", ""),
  status = Sys.getenv("PSILO_STATUS", ""),
  topic = Sys.getenv("PSILO_TOPIC", ""),
  substance = Sys.getenv("PSILO_SUBSTANCE", "")
)

if (is.na(cfg$max_papers) || cfg$max_papers < 20) cfg$max_papers <- 300

dir.create(cfg$output_dir, recursive = TRUE, showWarnings = FALSE)
dir.create(file.path(cfg$output_dir, "tables"), recursive = TRUE, showWarnings = FALSE)
dir.create(file.path(cfg$output_dir, "figures"), recursive = TRUE, showWarnings = FALSE)
dir.create(file.path(cfg$output_dir, "network"), recursive = TRUE, showWarnings = FALSE)

message("Downloading current database from: ", cfg$db_url)
db_file <- file.path(cfg$output_dir, "psilocybin-research-publications.sqlite")
download.file(cfg$db_url, db_file, mode = "wb", quiet = FALSE)

con <- DBI::dbConnect(RSQLite::SQLite(), db_file)
on.exit(DBI::dbDisconnect(con), add = TRUE)

table_exists <- function(name) {
  name %in% DBI::dbListTables(con)
}

normalize_doi <- function(x) {
  x <- tolower(trimws(as.character(x)))
  x <- str_replace(x, "^https?://(dx\\.)?doi\\.org/", "")
  x <- str_replace(x, "^doi:\\s*", "")
  x[x == "" | is.na(x)] <- NA_character_
  x
}

split_csv_field <- function(x) {
  x <- as.character(x)
  if (length(x) == 0 || is.na(x) || !nzchar(trimws(x))) return(character())
  str_split(x, "\\s*,\\s*|\\s*;\\s*")[[1]] |>
    trimws() |>
    discard(~ .x == "")
}

extract_reference_dois <- function(raw_json) {
  if (is.na(raw_json) || !nzchar(raw_json)) return(character())
  raw <- tryCatch(jsonlite::fromJSON(raw_json, simplifyVector = FALSE), error = function(e) NULL)
  if (is.null(raw)) return(character())

  values <- character()
  for (key in c("reference_dois", "references")) {
    items <- raw[[key]]
    if (is.null(items)) next
    if (is.character(items)) {
      values <- c(values, items)
    } else if (is.list(items)) {
      values <- c(values, purrr::map_chr(items, function(item) {
        if (is.character(item) && length(item) == 1) return(item)
        if (is.list(item) && !is.null(item$doi)) return(as.character(item$doi))
        NA_character_
      }))
    }
  }
  unique(na.omit(normalize_doi(values)))
}

safe_slug <- function(x) {
  x |>
    str_to_lower() |>
    str_replace_all("[^a-z0-9]+", "-") |>
    str_replace_all("(^-|-$)", "")
}

publications <- DBI::dbReadTable(con, "publications") |>
  as_tibble() |>
  mutate(
    id = as.integer(id),
    publication_year = suppressWarnings(as.integer(publication_year)),
    publication_date = suppressWarnings(as.Date(publication_date)),
    doi_norm = normalize_doi(doi),
    title = coalesce(title, ""),
    authors = coalesce(authors, ""),
    journal = na_if(trimws(coalesce(journal, "")), ""),
    source_name = coalesce(source_name, ""),
    publication_status = coalesce(publication_status, "published"),
    topic_tags = coalesce(topic_tags, ""),
    substance_tags = coalesce(substance_tags, ""),
    study_type = coalesce(study_type, ""),
    raw_json = coalesce(raw_json, "")
  ) |>
  filter(hidden == 0, false_positive == 0)

if (nzchar(cfg$from_year)) {
  min_year <- suppressWarnings(as.integer(cfg$from_year))
  if (!is.na(min_year)) {
    publications <- publications |> filter(is.na(publication_year) | publication_year >= min_year)
  }
}

if (nzchar(cfg$status)) {
  status_filter <- cfg$status |> str_split("\\s*,\\s*") |> unlist() |> str_to_lower()
  publications <- publications |> filter(str_to_lower(publication_status) %in% status_filter)
}

if (nzchar(cfg$topic)) {
  publications <- publications |> filter(str_detect(str_to_lower(topic_tags), fixed(str_to_lower(cfg$topic))))
}

if (nzchar(cfg$substance)) {
  publications <- publications |> filter(str_detect(str_to_lower(substance_tags), fixed(str_to_lower(cfg$substance))))
}

if (nrow(publications) == 0) {
  stop("No publications match the current filters.")
}

authors <- if (table_exists("publication_authors")) {
  DBI::dbReadTable(con, "publication_authors") |>
    as_tibble() |>
    transmute(publication_id = as.integer(publication_id), author_name = trimws(author_name), position = as.integer(position)) |>
    inner_join(publications |> select(id), by = c("publication_id" = "id")) |>
    filter(nzchar(author_name))
} else {
  publications |>
    select(publication_id = id, authors) |>
    mutate(author_name = map(authors, split_csv_field)) |>
    unnest(author_name) |>
    group_by(publication_id) |>
    mutate(position = row_number()) |>
    ungroup()
}

topics <- if (table_exists("publication_topics")) {
  DBI::dbReadTable(con, "publication_topics") |>
    as_tibble() |>
    transmute(publication_id = as.integer(publication_id), topic = trimws(topic), source = coalesce(source, "classifier")) |>
    inner_join(publications |> select(id), by = c("publication_id" = "id")) |>
    filter(nzchar(topic))
} else {
  publications |>
    select(publication_id = id, topic_tags) |>
    mutate(topic = map(topic_tags, split_csv_field)) |>
    unnest(topic) |>
    mutate(source = "classifier")
}

references <- publications |>
  select(publication_id = id, source_doi = doi_norm, title, publication_year, raw_json) |>
  mutate(reference_doi = map(raw_json, extract_reference_dois)) |>
  select(-raw_json) |>
  unnest(reference_doi) |>
  filter(!is.na(source_doi), !is.na(reference_doi), source_doi != reference_doi) |>
  distinct(publication_id, source_doi, reference_doi, .keep_all = TRUE)

internal_dois <- publications |> filter(!is.na(doi_norm)) |> select(target_id = id, reference_doi = doi_norm, target_title = title)
citation_edges <- references |>
  left_join(internal_dois, by = "reference_doi") |>
  mutate(edge_scope = if_else(is.na(target_id), "external_reference", "internal_citation"))

year_summary <- publications |>
  count(publication_year, sort = TRUE, name = "records") |>
  arrange(publication_year)

status_summary <- publications |>
  count(publication_status, sort = TRUE, name = "records")

journal_summary <- publications |>
  mutate(journal = coalesce(journal, "Unknown journal")) |>
  count(journal, sort = TRUE, name = "records")

author_summary <- authors |>
  count(author_name, sort = TRUE, name = "records")

topic_summary <- topics |>
  count(topic, sort = TRUE, name = "records")

study_type_summary <- publications |>
  mutate(study_type = if_else(nzchar(study_type), study_type, "Unclassified")) |>
  count(study_type, sort = TRUE, name = "records")

source_summary <- publications |>
  mutate(source_name = if_else(nzchar(source_name), source_name, "Unknown source")) |>
  count(source_name, sort = TRUE, name = "records")

readr::write_csv(publications, file.path(cfg$output_dir, "tables", "publications.csv"))
readr::write_csv(authors, file.path(cfg$output_dir, "tables", "publication_authors.csv"))
readr::write_csv(topics, file.path(cfg$output_dir, "tables", "publication_topics.csv"))
readr::write_csv(citation_edges, file.path(cfg$output_dir, "tables", "citation_edges.csv"))
readr::write_csv(year_summary, file.path(cfg$output_dir, "tables", "records_by_year.csv"))
readr::write_csv(journal_summary, file.path(cfg$output_dir, "tables", "top_journals.csv"))
readr::write_csv(author_summary, file.path(cfg$output_dir, "tables", "top_authors.csv"))
readr::write_csv(topic_summary, file.path(cfg$output_dir, "tables", "top_topics.csv"))
readr::write_csv(status_summary, file.path(cfg$output_dir, "tables", "status_summary.csv"))
readr::write_csv(source_summary, file.path(cfg$output_dir, "tables", "source_summary.csv"))
readr::write_csv(study_type_summary, file.path(cfg$output_dir, "tables", "study_type_summary.csv"))

plot_years <- ggplot(year_summary |> filter(!is.na(publication_year)), aes(publication_year, records)) +
  geom_col(fill = "#2f6f5e", width = 0.8) +
  geom_line(color = "#7a5c2e", linewidth = 0.8) +
  geom_point(color = "#123c31", size = 1.8) +
  labs(title = "Psilocybin/psilocin records by publication year", x = "Publication year", y = "Records") +
  theme_minimal(base_size = 12)

plot_journals <- journal_summary |>
  slice_head(n = 20) |>
  mutate(journal = reorder(journal, records)) |>
  ggplot(aes(records, journal)) +
  geom_col(fill = "#366f91") +
  labs(title = "Top journals", x = "Records", y = NULL) +
  theme_minimal(base_size = 12)

plot_topics <- topic_summary |>
  slice_head(n = 20) |>
  mutate(topic = reorder(topic, records)) |>
  ggplot(aes(records, topic)) +
  geom_col(fill = "#7a5c2e") +
  labs(title = "Top research topics", x = "Records", y = NULL) +
  theme_minimal(base_size = 12)

ggsave(file.path(cfg$output_dir, "figures", "records_by_year.pdf"), plot_years, width = 10, height = 6)
ggsave(file.path(cfg$output_dir, "figures", "top_journals.pdf"), plot_journals, width = 10, height = 7)
ggsave(file.path(cfg$output_dir, "figures", "top_topics.pdf"), plot_topics, width = 10, height = 7)

seed_papers <- publications |>
  mutate(reference_count = map_int(raw_json, ~ length(extract_reference_dois(.x)))) |>
  arrange(desc(reference_count), desc(publication_year), desc(id)) |>
  slice_head(n = cfg$max_papers)

paper_nodes <- seed_papers |>
  transmute(
    id = paste0("paper:", id),
    label = str_trunc(title, 72),
    title = paste0(
      "<b>", htmltools::htmlEscape(title), "</b><br>",
      htmltools::htmlEscape(coalesce(authors, "")), "<br>",
      htmltools::htmlEscape(coalesce(journal, "Unknown journal")), " ",
      coalesce(as.character(publication_year), ""), "<br>",
      "Status: ", htmltools::htmlEscape(publication_status), "<br>",
      if_else(!is.na(doi_norm), paste0("DOI: ", htmltools::htmlEscape(doi_norm)), "")
    ),
    group = "Paper",
    value = pmax(8, pmin(40, reference_count + 8)),
    url = if_else(!is.na(doi_norm), paste0("https://doi.org/", doi_norm), coalesce(source_url, "")),
    publication_year = publication_year
  )

seed_refs <- seed_papers |>
  select(publication_id = id, source_doi = doi_norm, title, raw_json) |>
  mutate(reference_doi = map(raw_json, extract_reference_dois)) |>
  select(-raw_json) |>
  unnest(reference_doi) |>
  filter(!is.na(reference_doi)) |>
  distinct(publication_id, reference_doi)

reference_counts <- seed_refs |>
  count(reference_doi, sort = TRUE, name = "cited_by_seed_count") |>
  slice_head(n = max(75, min(500, cfg$max_papers * 3)))

reference_nodes <- reference_counts |>
  left_join(publications |> select(doi_norm, matched_id = id, matched_title = title, matched_year = publication_year), by = c("reference_doi" = "doi_norm")) |>
  transmute(
    id = paste0("ref:", safe_slug(reference_doi)),
    label = if_else(!is.na(matched_title), str_trunc(matched_title, 64), str_trunc(reference_doi, 48)),
    title = paste0(
      if_else(!is.na(matched_title), paste0("<b>", htmltools::htmlEscape(matched_title), "</b><br>"), ""),
      "Reference DOI: ", htmltools::htmlEscape(reference_doi), "<br>",
      "Cited by seed papers: ", cited_by_seed_count
    ),
    group = if_else(is.na(matched_id), "External DOI reference", "Indexed cited paper"),
    value = pmax(6, pmin(36, cited_by_seed_count * 3)),
    url = paste0("https://doi.org/", reference_doi),
    publication_year = matched_year
  )

network_edges <- seed_refs |>
  semi_join(reference_counts, by = "reference_doi") |>
  transmute(
    from = paste0("paper:", publication_id),
    to = paste0("ref:", safe_slug(reference_doi)),
    arrows = "to",
    title = "cites",
    color = "#a6b5ad"
  )

author_edges <- authors |>
  semi_join(seed_papers |> select(id), by = c("publication_id" = "id")) |>
  group_by(author_name) |>
  filter(n() >= 2) |>
  ungroup() |>
  mutate(author_id = paste0("author:", safe_slug(author_name))) |>
  transmute(from = paste0("paper:", publication_id), to = author_id, title = "author", color = "#d7c9a0", arrows = "")

author_nodes <- author_edges |>
  distinct(id = to) |>
  mutate(
    label = str_replace(id, "^author:", "") |> str_replace_all("-", " ") |> str_to_title(),
    title = paste0("Author: ", htmltools::htmlEscape(label)),
    group = "Author",
    value = 10,
    url = ""
  )

nodes <- bind_rows(paper_nodes, reference_nodes, author_nodes) |>
  distinct(id, .keep_all = TRUE)

edges <- bind_rows(network_edges, author_edges) |>
  filter(from %in% nodes$id, to %in% nodes$id) |>
  distinct(from, to, title, .keep_all = TRUE)

graph <- igraph::graph_from_data_frame(edges |> select(from, to), directed = TRUE, vertices = nodes |> select(id))
centrality <- tibble(id = names(igraph::degree(graph, mode = "all")), degree = as.numeric(igraph::degree(graph, mode = "all")))
nodes <- nodes |>
  left_join(centrality, by = "id") |>
  mutate(
    degree = coalesce(degree, 0),
    value = pmax(value, pmin(45, 6 + degree * 2)),
    borderWidth = if_else(group == "Paper", 2, 1)
  )

readr::write_csv(nodes, file.path(cfg$output_dir, "tables", "network_nodes.csv"))
readr::write_csv(edges, file.path(cfg$output_dir, "tables", "network_edges.csv"))

network_html <- visNetwork::visNetwork(nodes, edges, width = "100%", height = "850px", main = "Psilocybin Research Tracker citation network") |>
  visNetwork::visGroups(groupname = "Paper", color = list(background = "#2f6f5e", border = "#123c31"), shape = "dot") |>
  visNetwork::visGroups(groupname = "Indexed cited paper", color = list(background = "#366f91", border = "#19384d"), shape = "dot") |>
  visNetwork::visGroups(groupname = "External DOI reference", color = list(background = "#e8dfc4", border = "#7a5c2e"), shape = "dot") |>
  visNetwork::visGroups(groupname = "Author", color = list(background = "#f2c879", border = "#7a5c2e"), shape = "triangle") |>
  visNetwork::visOptions(highlightNearest = list(enabled = TRUE, degree = 1, hover = TRUE), nodesIdSelection = TRUE, selectedBy = "group") |>
  visNetwork::visInteraction(navigationButtons = TRUE, keyboard = TRUE, hover = TRUE, tooltipDelay = 80) |>
  visNetwork::visPhysics(
    solver = "forceAtlas2Based",
    forceAtlas2Based = list(gravitationalConstant = -80, centralGravity = 0.02, springLength = 140, springConstant = 0.05),
    stabilization = list(enabled = TRUE, iterations = 800)
  ) |>
  visNetwork::visLayout(randomSeed = 42) |>
  visNetwork::visLegend(width = 0.2, position = "right", main = "Node type")

network_file <- file.path(cfg$output_dir, "network", "psilocybin_citation_network.html")
htmlwidgets::saveWidget(network_html, network_file, selfcontained = TRUE, title = "Psilocybin citation network")

pdf_file <- file.path(cfg$output_dir, "network", "psilocybin_citation_network.pdf")
png_file <- file.path(cfg$output_dir, "network", "psilocybin_citation_network.png")

snapshot_result <- tryCatch({
  webshot2::webshot(network_file, file = pdf_file, vwidth = 1600, vheight = 1100, zoom = 0.8, delay = 2)
  webshot2::webshot(network_file, file = png_file, vwidth = 1600, vheight = 1100, zoom = 0.8, delay = 2)
  "PDF and PNG network snapshots created."
}, error = function(e) {
  paste("Interactive HTML was created. PDF/PNG snapshot skipped because webshot2 could not access a browser:", conditionMessage(e))
})

summary_html <- htmltools::tagList(
  htmltools::tags$html(
    htmltools::tags$head(
      htmltools::tags$meta(charset = "utf-8"),
      htmltools::tags$title("Psilocybin Research Tracker bibliometrics"),
      htmltools::tags$style(htmltools::HTML("
        body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:0;background:#f7f5ee;color:#1d2b25}
        main{max-width:1120px;margin:0 auto;padding:32px}
        h1{font-size:34px;margin-bottom:4px} h2{margin-top:32px}
        .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:24px 0}
        .card{background:white;border:1px solid #e2dac8;border-radius:10px;padding:16px}
        .card strong{display:block;font-size:28px;color:#2f6f5e}
        a{color:#1a6b54;font-weight:700}.note{color:#68746d}.grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}
        @media(max-width:760px){.grid{grid-template-columns:1fr}main{padding:20px}}
      "))
    ),
    htmltools::tags$body(
      htmltools::tags$main(
        htmltools::tags$p(class = "note", "Generated from the live public SQLite database downloaded from psilocybin-research.com."),
        htmltools::tags$h1("Psilocybin Research Tracker bibliometrics"),
        htmltools::tags$div(class = "cards",
          htmltools::tags$div(class = "card", htmltools::tags$strong(nrow(publications)), htmltools::tags$span("records analyzed")),
          htmltools::tags$div(class = "card", htmltools::tags$strong(n_distinct(publications$journal, na.rm = TRUE)), htmltools::tags$span("journals")),
          htmltools::tags$div(class = "card", htmltools::tags$strong(n_distinct(authors$author_name, na.rm = TRUE)), htmltools::tags$span("authors")),
          htmltools::tags$div(class = "card", htmltools::tags$strong(nrow(references)), htmltools::tags$span("reference DOI edges"))
        ),
        htmltools::tags$p(htmltools::tags$a(href = "network/psilocybin_citation_network.html", "Open interactive citation network")),
        htmltools::tags$p(class = "note", snapshot_result),
        htmltools::tags$h2("Top journals"),
        DT::datatable(journal_summary |> slice_head(n = 25), rownames = FALSE, options = list(pageLength = 10)),
        htmltools::tags$h2("Top authors"),
        DT::datatable(author_summary |> slice_head(n = 25), rownames = FALSE, options = list(pageLength = 10)),
        htmltools::tags$h2("Top topics"),
        DT::datatable(topic_summary |> slice_head(n = 25), rownames = FALSE, options = list(pageLength = 10))
      )
    )
  )
)

summary_file <- file.path(cfg$output_dir, "bibliometric_report.html")
htmltools::save_html(summary_html, summary_file)

message("\nDone.")
message("Records analyzed: ", nrow(publications))
message("Citation/reference edges: ", nrow(references))
message("Interactive network: ", normalizePath(network_file, mustWork = FALSE))
message("Bibliometric report: ", normalizePath(summary_file, mustWork = FALSE))
message(snapshot_result)
