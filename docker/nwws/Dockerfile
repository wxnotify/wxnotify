FROM golang:1.27 AS build
WORKDIR /src
COPY pipeline/nwws-go-client/go.mod pipeline/nwws-go-client/go.sum ./
COPY pipeline/nwws-go-client/third_party ./third_party
RUN go mod download
COPY pipeline/nwws-go-client/cmd ./cmd
COPY pipeline/nwws-go-client/internal ./internal
RUN CGO_ENABLED=0 go build -o /nwws-go-client ./cmd/nwws-go-client

FROM alpine:3.20
RUN apk add --no-cache curl ca-certificates
COPY --from=build /nwws-go-client /nwws-go-client
COPY docker/nwws/pan-notify.sh /pan-notify.sh
COPY docker/nwws/cleanup-products.sh /cleanup-products.sh
COPY docker/nwws/entrypoint.sh /entrypoint.sh
RUN chmod +x /pan-notify.sh /cleanup-products.sh /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
