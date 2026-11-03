#!/usr/bin/env Rscript
# RDS workflow reproducing annual-counts.sql on the SAME verified snapshot.
# Required R packages: jsonlite and digest. No RSQLite/Arrow is required.
# Rscript annual_counts.R OUTPUT_DIRECTORY [SAVED_DATASET_MANIFEST.json]
stopifnot(requireNamespace("jsonlite", quietly = TRUE),
          requireNamespace("digest", quietly = TRUE))
args <- commandArgs(trailingOnly = TRUE)
if (length(args) < 1L || length(args) > 2L) stop("Usage: Rscript annual_counts.R OUTPUT [MANIFEST.json]")
output <- args[[1L]]
if (file.exists(output)) stop("Choose a new output directory; existing research inputs are never overwritten")
if (!dir.create(output, recursive = TRUE)) stop("Cannot create output directory")
options(HTTPUserAgent = "PsilocybinReproducibleRExample/1.0")
json <- function(path) jsonlite::fromJSON(path, simplifyVector = FALSE)
sha <- function(path) digest::digest(file = path, algo = "sha256", serialize = FALSE)
retrieve <- function(url, destination) {
  if (!grepl("^https://[^/@]+/|^http://(127[.]0[.]0[.]1|localhost)(:[0-9]+)?/", url)) stop("Use a public HTTPS URL or localhost fixture")
  status <- download.file(url, destination, mode = "wb", method = "libcurl", quiet = TRUE)
  if (status != 0L || file.info(destination)$size > 128 * 1024 * 1024) stop("Download failed or exceeds example capacity")
}
manifestFile <- file.path(output, "dataset-manifest.json")
if (length(args) == 2L) {
  if (!file.copy(args[[2L]], manifestFile, overwrite = FALSE)) stop("Cannot preserve input manifest")
} else retrieve("https://psilocybin-research.com/dataset-manifest.json", manifestFile)
manifest <- json(manifestFile)
stopifnot(isTRUE(manifest$complete), grepl("^[a-f0-9]{64}$", manifest$snapshot_id))
if (identical(manifest$is_current, FALSE)) message("Using a completed historical snapshot, not the latest live catalogue")
artifact <- function(name, filename) {
  entry <- manifest$artifacts[[name]]
  origin <- sub("^(https?://[^/]+).*$", "\\1", manifest$manifest_url)
  stopifnot(startsWith(entry$url, paste0(origin, "/dataset-download.php?")),
            grepl(paste0("snapshot=", manifest$snapshot_id), entry$url, fixed = TRUE),
            grepl(paste0("format=", name), entry$url, fixed = TRUE),
            identical(entry$record_count, manifest$record_count))
  path <- file.path(output, filename)
  retrieve(entry$url, path)
  stopifnot(file.info(path)$size == entry$bytes, identical(sha(path), entry$sha256))
  path
}
rdsFile <- artifact("rds", "publications.rds")
statisticsFile <- artifact("statistics-json", "publications-by-year.json")
research <- readRDS(rdsFile)
papers <- research$publications
metadata <- setNames(research$metadata$value, research$metadata$key)
stopifnot(nrow(papers) == manifest$record_count, !anyDuplicated(papers$id),
          identical(unname(metadata[["generated_at_utc"]]), manifest$generated_at_utc))
fields <- c("year", "total", "published", "preprints", "trial_registrations", "other")
years <- c(sort(unique(papers$publication_year[!is.na(papers$publication_year)]), decreasing = TRUE), NA_integer_)
counts <- as.data.frame(do.call(rbind, lapply(years, function(year) {
  selected <- if (is.na(year)) is.na(papers$publication_year) else !is.na(papers$publication_year) & papers$publication_year == year
  status <- papers$publication_status[selected]
  c(year = year, total = sum(selected), published = sum(status == "published", na.rm = TRUE),
    preprints = sum(status == "preprint", na.rm = TRUE),
    trial_registrations = sum(status == "clinical trial", na.rm = TRUE),
    other = sum(is.na(status) | !status %in% c("published", "preprint", "clinical trial")))
})), check.names = FALSE)
statistics <- json(statisticsFile)
stopifnot(identical(statistics$catalogue_revision, manifest$catalogue_revision),
          identical(statistics$generated_at_utc, manifest$generated_at_utc))
expected <- if (length(statistics$years)) as.data.frame(do.call(rbind, lapply(statistics$years, function(row) unlist(row[fields])))) else as.data.frame(setNames(rep(list(numeric()), length(fields)), fields))
# Explicit missing-year category is preserved even if its count is zero.
missing <- c(year = NA_real_, unlist(statistics$missing_year))
expected <- rbind(expected, as.data.frame(as.list(missing[fields])))
rownames(expected) <- NULL
stopifnot(isTRUE(all.equal(counts, expected, check.attributes = FALSE)), sum(counts$total) == nrow(papers))
write.csv(counts, file.path(output, "annual-counts.csv"), row.names = FALSE, na = "", fileEncoding = "UTF-8")
provenance <- list(status = "verified", snapshot_id = manifest$snapshot_id,
                   catalogue_revision = manifest$catalogue_revision, record_count = nrow(papers),
                   generated_at_utc = manifest$generated_at_utc,
                   retrieved_at_utc = format(Sys.time(), "%Y-%m-%dT%H:%M:%SZ", tz = "UTC"),
                   manifest_sha256 = sha(manifestFile), rds_sha256 = sha(rdsFile),
                   source_sqlite_sha256 = manifest$artifacts$rds$source_sha256,
                   statistics_sha256 = sha(statisticsFile),
                   interpretation = "Indexed records, not independent studies or participants. Missing years remain separate; current-year coverage is partial. Classifications and coverage require source checks.")
jsonlite::write_json(provenance, file.path(output, "analysis-provenance.json"), auto_unbox = TRUE, pretty = TRUE)
message("Verified ", nrow(papers), " records; ", nrow(counts), " year categories including missing years.")
