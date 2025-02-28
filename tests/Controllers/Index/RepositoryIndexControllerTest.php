<?php

namespace Binaryk\LaravelRestify\Tests\Controllers\Index;

use Binaryk\LaravelRestify\Repositories\Repository;
use Binaryk\LaravelRestify\Restify;
use Binaryk\LaravelRestify\Tests\Factories\PostFactory;
use Binaryk\LaravelRestify\Tests\Fixtures\Company\Company;
use Binaryk\LaravelRestify\Tests\Fixtures\Company\CompanyRepository;
use Binaryk\LaravelRestify\Tests\Fixtures\Post\Post;
use Binaryk\LaravelRestify\Tests\Fixtures\Post\PostMergeableRepository;
use Binaryk\LaravelRestify\Tests\Fixtures\Post\PostRepository;
use Binaryk\LaravelRestify\Tests\Fixtures\Post\RelatedCastWithAttributes;
use Binaryk\LaravelRestify\Tests\Fixtures\User\User;
use Binaryk\LaravelRestify\Tests\IntegrationTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\Fluent\AssertableJson;

class RepositoryIndexControllerTest extends IntegrationTest
{
    use RefreshDatabase;

    /** * @test */
    public function it_can_paginate(): void
    {
        PostFactory::many(15);

        PostRepository::$defaultPerPage = 5;

        $this->getJson(PostRepository::to())
            ->assertJson(
                fn (AssertableJson $json) => $json
                ->count('data', 5)
                ->etc()
            );

        $this->getJson(PostRepository::to(null, [
            'perPage' => 10,
        ]))->assertJson(
            fn (AssertableJson $json) => $json
            ->count('data', 10)
            ->etc()
        );

        $this->getJson(PostRepository::to(null, [
            'perPage' => 10,
            'page' => '2',
        ]))->assertJson(
            fn (AssertableJson $json) => $json
            ->count('data', 5)
            ->etc()
        );
    }

    /** * @test */
    public function it_can_search_using_query(): void
    {
        PostFactory::one([
            'title' => 'Title with code word',
        ]);

        PostFactory::one([
            'title' => 'Another title with code inner',
        ]);

        PostFactory::one([
            'title' => 'A title with no key word',
        ]);

        PostFactory::one([
            'title' => 'Lorem ipsum dolor',
        ]);

        PostRepository::$search = ['title'];

        $this->getJson(PostRepository::to(null, [
            'search' => 'code',
        ]))->assertJson(fn (AssertableJson $json) => $json->count('data', 2)->etc());
    }

    /** * @test */
    public function it_can_sort_using_query(): void
    {
        PostFactory::one([
            'title' => 'AAA',
        ]);

        PostFactory::one([
            'title' => 'ZZZ',
        ]);

        PostRepository::$sort = [
            'title',
        ];

        $this->getJson(PostRepository::to(null, [
            'sort' => '-title',
        ]))->assertJson(
            fn (AssertableJson $json) => $json
            ->where('data.0.attributes.title', 'ZZZ')
            ->where('data.1.attributes.title', 'AAA')
            ->etc()
        );

        $this->getJson(PostRepository::to(null, [
            'sort' => 'title',
        ]))->assertJson(
            fn (AssertableJson $json) => $json
            ->where('data.0.attributes.title', 'AAA')
            ->where('data.1.attributes.title', 'ZZZ')
            ->etc()
        );
    }

    /** * @test */
    public function it_can_return_related_entity(): void
    {
        PostRepository::$related = [
            'user',
        ];

        Post::factory()->for(
            User::factory()->state([
                'name' => $name = 'John Doe',
            ])
        )->create();

        $this->getJson(PostRepository::to(null, [
            'related' => 'user',
        ]))->assertJson(
            fn (AssertableJson $json) => $json
            ->where('data.0.relationships.user.0.name', $name)
            ->etc()
        );
    }

    public function test_repository_can_resolve_related_using_callables(): void
    {
        PostRepository::$related = [
            'user' => function ($request, $repository) {
                $this->assertInstanceOf(Request::class, $request);
                $this->assertInstanceOf(Repository::class, $repository);

                return 'foo';
            },
        ];

        PostFactory::one();

        $this->getJson(PostRepository::to(null, [
            'related' => 'user',
        ]))->assertJson(
            fn (AssertableJson $json) => $json
            ->where('data.0.relationships.user', 'foo')
            ->etc()
        );
    }

    /** * @test */
    public function it_can_transform_relationship_format_using_config(): void
    {
        PostRepository::$related = ['user'];

        config([
            'restify.casts.related' => RelatedCastWithAttributes::class,
        ]);

        PostFactory::one();

        $this->getJson(PostRepository::to(null, [
            'related' => 'user',
        ]))->assertJson(
            fn (AssertableJson $json) => $json
            ->has('data.0.relationships.user.0.attributes')
            ->etc()
        );
    }

    /** * @test */
    public function it_can_retrieve_nested_relationships(): void
    {
        CompanyRepository::partialMock()
            ->shouldReceive('related')
            ->andReturn([
                'users.posts',
            ]);

        Company::factory()->has(
            User::factory()->has(
                Post::factory()
            )
        )->create();

        $response = $this->getJson(CompanyRepository::to(null, [
            'related' => 'users.posts',
        ]))->assertJson(
            fn (AssertableJson $json) => $json
            ->has('data.0.relationships')
            ->etc()
        );

        self::assertCount(1, $response->json('data.0.relationships')['users.posts']);
        self::assertCount(1, $response->json('data.0.relationships')['users.posts'][0]['posts']);
    }

    /** * @test */
    public function it_can_paginate_keeping_relationships(): void
    {
        PostRepository::$related = [
            'user',
        ];

        PostRepository::$sort = [
            'id',
        ];

        PostFactory::many(5);

        Post::factory()->for(User::factory()->state([
            'name' => $owner = 'John Doe',
        ]))->create();

        $this->getJson(PostRepository::to(null, [
            'perPage' => 5,
            'related' => 'user',
            'sort' => 'id',
            'page' => 2,
        ]))
            ->assertJson(
                fn (AssertableJson $json) => $json
                ->count('data', 1)
                ->where('data.0.relationships.user.0.name', $owner)
                ->etc()
            );
    }

    public function test_index_unmergeable_repository_contains_only_explicitly_defined_fields(): void
    {
        PostFactory::one();

        $response = $this->getJson(PostRepository::to())
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    [
                        'attributes' => [
                            'user_id',
                            'title',
                            'description',
                        ],
                    ],
                ],
            ]);

        $this->assertArrayNotHasKey('image', $response->json('data.0.attributes'));
    }

    public function test_index_mergeable_repository_contains_model_attributes_and_local_fields(): void
    {
        Restify::repositories([
            PostMergeableRepository::class,
        ]);

        $this->getJson(PostMergeableRepository::to(
            $this->mockPost()->id
        ))->assertJsonStructure([
            'data' => [
                'attributes' => [
                    'user_id',
                    'title',
                    'description',
                    'image',
                ],
            ],
        ]);
    }

    public function test_can_add_custom_index_main_meta_attributes(): void
    {
        Post::factory()->create([
            'title' => 'Post Title',
        ]);

        $response = $this->getJson(PostRepository::to())
            ->assertJsonStructure([
                'meta' => [
                    'postKey',
                ],
            ]);

        $this->assertEquals('Custom Meta Value', $response->json('meta.postKey'));
        $this->assertEquals('Post Title', $response->json('meta.first_title'));
    }
}
