FROM golang:1.27 AS build
WORKDIR /src
COPY docker/emwin-http/go.mod ./
COPY docker/emwin-http/cmd ./cmd
COPY docker/emwin-http/internal ./internal
RUN go vet ./... && go test ./...
RUN CGO_ENABLED=0 go build -o /emwin-http ./cmd/emwin-http

FROM alpine:3.20
RUN apk add --no-cache ca-certificates tzdata
COPY --from=build /emwin-http /emwin-http
COPY docker/emwin-http/config.json /config/config.json
HEALTHCHECK --interval=60s --timeout=5s --start-period=120s --retries=3 CMD ["/emwin-http", "--healthcheck"]
ENTRYPOINT ["/emwin-http"]
