#!/bin/bash

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

BASE_URL="${1:-http://localhost:8000}"
TOKEN="${2:-your_token_here}"

echo -e "${BLUE}=== Testing Address Autocomplete API ===${NC}\n"

# Test 1: French address autocomplete (Adresse API)
echo -e "${YELLOW}Test 1: French address autocomplete${NC}"
curl -s -X GET "${BASE_URL}/api/address/autocomplete?q=Paris&country=FR&limit=3" \
  -H "Content-Type: application/json" | jq '.
  '
echo ""

# Test 2: French address autocomplete without country param (default to Adresse API)
echo -e "${YELLOW}Test 2: French address (no country param)${NC}"
curl -s -X GET "${BASE_URL}/api/address/autocomplete?q=Lyon&limit=3" \
  -H "Content-Type: application/json" | jq '.'
echo ""

# Test 3: International address (Mapbox)
echo -e "${YELLOW}Test 3: International address (London - Mapbox)${NC}"
curl -s -X GET "${BASE_URL}/api/address/autocomplete?q=London&country=GB&limit=3" \
  -H "Content-Type: application/json" | jq '.'
echo ""

# Test 4: Spanish address (Mapbox)
echo -e "${YELLOW}Test 4: Spanish address (Madrid - Mapbox)${NC}"
curl -s -X GET "${BASE_URL}/api/address/autocomplete?q=Madrid&country=ES&limit=3" \
  -H "Content-Type: application/json" | jq '.'
echo ""

# Test 5: Reverse geocode (Paris coordinates)
echo -e "${YELLOW}Test 5: Reverse geocode (Paris: 48.8566, 2.3522)${NC}"
curl -s -X GET "${BASE_URL}/api/address/reverse-geocode?lat=48.8566&lon=2.3522" \
  -H "Content-Type: application/json" | jq '.'
echo ""

# Test 6: Reverse geocode London (international)
echo -e "${YELLOW}Test 6: Reverse geocode (London: 51.5074, -0.1278)${NC}"
curl -s -X GET "${BASE_URL}/api/address/reverse-geocode?lat=51.5074&lon=-0.1278&country=GB" \
  -H "Content-Type: application/json" | jq '.'
echo ""

# Test 7: Cache test - repeat same query (should be cached)
echo -e "${YELLOW}Test 7: Cache test (repeat Paris query - check 'cached' field)${NC}"
curl -s -X GET "${BASE_URL}/api/address/autocomplete?q=Paris&country=FR&limit=3" \
  -H "Content-Type: application/json" | jq '.'
echo ""

# Test 8: Missing query param
echo -e "${YELLOW}Test 8: Error handling - missing query${NC}"
curl -s -X GET "${BASE_URL}/api/address/autocomplete" \
  -H "Content-Type: application/json" | jq '.'
echo ""

echo -e "${GREEN}✅ All tests completed!${NC}"
echo -e "\n${BLUE}Check logs with:${NC}"
echo "tail -f var/log/shipping.log"
